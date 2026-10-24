<?php
//
// ZoneMinder API: a table, its associations and its validation rules.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Db;

/**
 * Base class of the API's models: one subclass per table (ZM\Api\Model\Monitor
 * for Monitors), declaring how its rows are named and nested in answers.
 *
 * Reading: find('all'|'first'|'count'|'list', $options) returns rows keyed by
 * alias, with the associated rows nested as clients have always received them:
 *
 *   array('Monitor' => array('Id' => 1, ...),        // the row itself
 *         'Monitor_Status' => array(...),            // belongsTo/hasOne: joined
 *         'Zone' => array(array(...), ...))          // hasMany/HABTM: lists
 *
 * How deep it goes is `recursive`: -1 the row only, 0 plus belongsTo/hasOne,
 * 1 plus hasMany and HABTM, 2 plus the associations of those.
 *
 * Writing: save($data, $id) inserts or updates after validation, delete($id)
 * deletes (and the dependent hasMany rows and the HABTM links).
 */
abstract class Model {
  /** The SQL table */
  const TABLE = '';
  /** The row's name in answers and in queries: SELECT ... FROM Monitors AS Monitor */
  const ALIAS = '';
  const PRIMARY_KEY = 'Id';
  /** The column find('list') shows */
  const DISPLAY_FIELD = 'Name';

  /** @var int default depth of find() */
  public $recursive = -1;

  /**
   * Associations, each alias => options:
   *   belongsTo:  'model' => class, 'foreignKey' => column of this table
   *   hasOne:     'model' => class, 'foreignKey' => column of the other table
   *   hasMany:    'model' => class, 'foreignKey' => column of the other table, 'dependent' => bool
   *   habtm:      'model' => class, 'joinTable', 'foreignKey' (join column for this table),
   *               'associationForeignKey' (join column for the other), 'with' (the
   *               join row's name in answers)
   * Any of them may add 'conditions' and 'order'.
   */
  protected $belongsTo = array();
  protected $hasOne = array();
  protected $hasMany = array();
  protected $habtm = array();

  /** @var array name => SQL expression, selected as extra columns of the row */
  protected $virtualFields = array();

  /**
   * @var array field => list of rules, each array('rule' => name or array(name, args...),
   *   'message' => text, 'allowEmpty' => bool, 'required' => bool, 'on' => 'create'|'update').
   *   Rules: numeric, notBlank, datetime, inList, isUnique, custom (a regex), or the
   *   name of a method of the model that takes the value and returns bool.
   */
  protected $validate = array();

  /** @var array field => list of messages, from the last save() */
  public $validationErrors = array();
  /** @var mixed the id of the last row saved */
  public $id;

  // ------------------------------------------------------------------ schema

  /** column => array('type' => SQL type, 'null' => bool, 'default' => value) */
  public function schema() {
    return Schema::columns(static::TABLE);
  }

  /** @return string[] */
  public function columns() {
    return array_keys($this->schema());
  }

  public function hasField($field) {
    return array_key_exists($field, $this->schema());
  }

  public function hasVirtualField($field) {
    return isset($this->virtualFields[$field]);
  }

  // ----------------------------------------------------------------- reading

  /**
   * @param string $type all, first, count or list
   * @param array $options conditions, fields, order, limit, page, offset, group, recursive,
   *   distinct (for count: count the distinct values of this field), and
   *   joins: extra joins, each array('table', 'alias', 'type' => 'LEFT', 'conditions' => SQL);
   *   contain: alias => true or a list of fields: hasMany/HABTM lists to attach whatever
   *   the depth, with only those fields
   */
  public function find($type = 'all', array $options = array()) {
    $recursive = array_key_exists('recursive', $options) ? $options['recursive'] : $this->recursive;
    $alias = static::ALIAS;
    $joins = ($recursive >= 0) ? $this->joinedAssociations() : array();

    $where = new Conditions($alias, $this->columns());
    $params = array();
    $sql = 'FROM '.Db::quoteName(static::TABLE).' AS '.Db::quoteName($alias);
    foreach ($joins as $assocAlias => $a) {
      $model = new $a['model']();
      $on = ($a['kind'] == 'belongsTo')
        ? Db::quoteName("$alias.{$a['foreignKey']}").' = '.Db::quoteName("$assocAlias.".$model::PRIMARY_KEY)
        : Db::quoteName("$alias.".static::PRIMARY_KEY).' = '.Db::quoteName("$assocAlias.{$a['foreignKey']}");
      if (!empty($a['conditions'])) {
        $c = new Conditions($assocAlias, $model->columns());
        $on .= ' AND '.$c->sql($a['conditions']);
        $params = array_merge($params, $c->params);
      }
      $sql .= ' LEFT JOIN '.Db::quoteName($model::TABLE).' AS '.Db::quoteName($assocAlias).' ON ('.$on.')';
    }
    foreach (isset($options['joins']) ? $options['joins'] : array() as $j) {
      $sql .= ' '.(isset($j['type']) ? strtoupper($j['type']) : 'LEFT').' JOIN '.Db::quoteName($j['table'])
        .' AS '.Db::quoteName(isset($j['alias']) ? $j['alias'] : $j['table']).' ON ('.$j['conditions'].')';
    }
    $conditionSql = $where->sql(isset($options['conditions']) ? $options['conditions'] : array());
    if ($conditionSql !== '') {
      $sql .= ' WHERE '.$conditionSql;
      $params = array_merge($params, $where->params);
    }

    if ($type == 'count') {
      // 'distinct' counts the distinct values of a field, for a query that groups by it.
      $what = isset($options['distinct']) ? 'COUNT(DISTINCT '.$where->qualify($options['distinct']).')' : 'COUNT(*)';
      return (int)Db::value("SELECT $what ".$sql, $params);
    }

    if (!empty($options['group'])) {
      $sql .= ' GROUP BY '.implode(', ', array_map(array($where, 'qualify'), (array)$options['group']));
    }
    $order = $this->orderSql($where, isset($options['order']) ? $options['order'] : null);
    if ($order !== '') $sql .= ' ORDER BY '.$order;
    $limit = isset($options['limit']) ? (int)$options['limit'] : null;
    if ($type == 'first') $limit = 1;
    if ($limit) {
      $offset = isset($options['offset']) ? (int)$options['offset']
        : (isset($options['page']) ? max(0, (min((int)$options['page'], 1000000000) - 1) * $limit) : 0);
      $sql .= ' LIMIT '.($offset ? "$offset, " : '').$limit;
    }

    if ($type == 'list') {
      $rows = Db::select('SELECT '.Db::quoteName("$alias.".static::PRIMARY_KEY).', '
        .Db::quoteName("$alias.".static::DISPLAY_FIELD).' '.$sql, $params);
      $list = array();
      foreach ($rows as $row) $list[$row[$alias][static::PRIMARY_KEY]] = $row[$alias][static::DISPLAY_FIELD];
      return $list;
    }

    if (empty($options['fields']) and $this->defaultFields() !== null) $options['fields'] = $this->defaultFields();
    $fields = !empty($options['fields']) ? $this->fieldList($where, (array)$options['fields'])
      : $this->selectList($alias, $joins);
    $rows = Db::select('SELECT '.$fields.' '.$sql, $params);
    $contain = isset($options['contain']) ? (array)$options['contain'] : null;
    if (count($rows) and ($recursive >= 1 or $contain)) {
      $rows = $this->attachMany($rows, $recursive, $contain);
    }
    $rows = $this->afterFind($rows);
    if ($type == 'first') return count($rows) ? $rows[0] : array();
    return $rows;
  }

  /** Whether a row with primary key $id exists. */
  public function exists($id) {
    if ($id === null or $id === '' or is_array($id)) return false;
    return Db::value('SELECT COUNT(*) FROM '.Db::quoteName(static::TABLE).' WHERE '
      .Db::quoteName(static::PRIMARY_KEY).' = ?', array($id)) > 0;
  }

  /** Change rows after they are read (e.g. computed fields). */
  protected function afterFind(array $rows) {
    return $rows;
  }

  /**
   * The fields a find() without 'fields' selects, or null for all of the row's and
   * its joined models'. Giving fields leaves the joined models out of the answer.
   */
  protected function defaultFields() {
    return null;
  }

  /** The columns a plain find() selects; a model may leave some out (Users' Password). */
  protected function readColumns() {
    return $this->columns();
  }

  /** @return array alias => model class, for the models find() joins at recursive >= 0 */
  public function joinedModels() {
    return array_map(function ($a) { return $a['model']; }, $this->joinedAssociations());
  }

  /** @return array alias => association options (+ 'kind'), for joined associations */
  private function joinedAssociations() {
    $out = array();
    foreach ($this->belongsTo as $a => $o) $out[$a] = $o + array('kind' => 'belongsTo');
    foreach ($this->hasOne as $a => $o) $out[$a] = $o + array('kind' => 'hasOne');
    return $out;
  }

  /** SELECT list: the row's columns and virtual fields, then those of each joined model. */
  private function selectList($alias, array $joins) {
    $parts = array();
    foreach ($this->readColumns() as $c) $parts[] = Db::quoteName("$alias.$c");
    foreach ($this->virtualFields as $name => $expr) $parts[] = "($expr) AS ".Db::quoteName("{$alias}__$name");
    foreach ($joins as $assocAlias => $a) {
      $model = new $a['model']();
      foreach ($model->readColumns() as $c) $parts[] = Db::quoteName("$assocAlias.$c");
      foreach ($model->virtualFields as $name => $expr) {
        $parts[] = '('.str_replace($model::ALIAS.'.', "$assocAlias.", $expr).') AS '.Db::quoteName("{$assocAlias}__$name");
      }
    }
    return implode(', ', $parts);
  }

  /** SELECT list from explicit fields; a virtual field may be named too. */
  private function fieldList(Conditions $where, array $fields) {
    $parts = array();
    foreach ($fields as $f) {
      $bare = (strpos($f, '.') !== false) ? substr($f, strrpos($f, '.') + 1) : $f;
      if (isset($this->virtualFields[$bare]) and (strpos($f, '.') === false or strpos($f, static::ALIAS.'.') === 0)) {
        $parts[] = '('.$this->virtualFields[$bare].') AS '.Db::quoteName(static::ALIAS.'__'.$bare);
      } else {
        $parts[] = $where->qualify($f);
      }
    }
    return implode(', ', $parts);
  }

  private function orderSql(Conditions $where, $order) {
    if ($order === null or $order === '' or $order === array()) return '';
    $parts = array();
    foreach ((array)$order as $k => $v) {
      if (is_string($k)) {
        $field = $k;
        $dir = $v;
      } else {
        $bits = preg_split('/\s+/', trim($v));
        $field = $bits[0];
        $dir = isset($bits[1]) ? $bits[1] : '';
      }
      $dir = strtoupper($dir);
      if ($dir !== '' and $dir !== 'ASC' and $dir !== 'DESC') {
        throw new \ZM\Api\Http\BadRequestException('Invalid sort direction: '.$dir);
      }
      if (isset($this->virtualFields[$field])) {
        $parts[] = '('.$this->virtualFields[$field].')'.($dir ? " $dir" : '');
      } else {
        $parts[] = $where->qualify($field).($dir ? " $dir" : '');
      }
    }
    return implode(', ', $parts);
  }

  /**
   * Add the hasMany and HABTM lists to each row: all of them, or with $contain
   * (alias => true or a field list) only those, with only those fields.
   */
  private function attachMany(array $rows, $recursive, $contain = null) {
    $alias = static::ALIAS;
    $ids = array();
    foreach ($rows as $row) {
      if (isset($row[$alias][static::PRIMARY_KEY])) $ids[] = $row[$alias][static::PRIMARY_KEY];
    }
    $ids = array_values(array_unique($ids));
    foreach ($this->hasMany as $assocAlias => $a) {
      if ($contain !== null and !array_key_exists($assocAlias, $contain)) continue;
      $model = new $a['model']();
      $conditions = array($model::ALIAS.'.'.$a['foreignKey'] => $ids);
      if (!empty($a['conditions'])) $conditions = array($conditions, $a['conditions']);
      $children = $model->find('all', array('conditions' => $conditions, 'recursive' => $recursive - 2,
        'order' => isset($a['order']) ? $a['order'] : null));
      $byParent = array();
      foreach ($children as $child) {
        $flat = $child[$model::ALIAS];
        unset($child[$model::ALIAS]);
        $byParent[$flat[$a['foreignKey']]][] = $flat + $child;
      }
      foreach ($rows as &$row) {
        $id = $row[$alias][static::PRIMARY_KEY];
        $row[$assocAlias] = isset($byParent[$id]) ? $byParent[$id] : array();
      }
      unset($row);
    }
    foreach ($this->habtm as $assocAlias => $a) {
      if ($contain !== null and !array_key_exists($assocAlias, $contain)) continue;
      $model = new $a['model']();
      $with = $a['with'];
      $restricted = (is_array($contain) and is_array($contain[$assocAlias]));
      $targetFields = $restricted ? $contain[$assocAlias] : $model->readColumns();
      $virtual = array();
      if (!$restricted) {
        foreach ($model->virtualFields as $name => $expr) $virtual[] = "($expr) AS ".Db::quoteName($model::ALIAS."__$name");
      }
      $sql = 'SELECT '.implode(', ', array_merge(
          array_map(function ($c) use ($model) { return Db::quoteName($model::ALIAS.".$c"); }, $targetFields),
          $virtual,
          array_map(function ($c) use ($with) { return Db::quoteName("$with.$c"); }, self::tableColumns($a['joinTable']))))
        .' FROM '.Db::quoteName($model::TABLE).' AS '.Db::quoteName($model::ALIAS)
        .' JOIN '.Db::quoteName($a['joinTable']).' AS '.Db::quoteName($with)
        .' ON ('.Db::quoteName("$with.{$a['associationForeignKey']}").' = '.Db::quoteName($model::ALIAS.'.'.$model::PRIMARY_KEY).')'
        .' WHERE '.Db::quoteName("$with.{$a['foreignKey']}").' IN ('.implode(', ', array_fill(0, count($ids), '?')).')';
      $byParent = array();
      foreach (Db::select($sql, $ids) as $r) {
        $byParent[$r[$with][$a['foreignKey']]][] = $r[$model::ALIAS] + array($with => $r[$with]);
      }
      foreach ($rows as &$row) {
        $id = $row[$alias][static::PRIMARY_KEY];
        $row[$assocAlias] = isset($byParent[$id]) ? $byParent[$id] : array();
      }
      unset($row);
    }
    return $rows;
  }

  /** The columns of a table that has no model of its own (join tables). */
  private static function tableColumns($table) {
    return array_keys(Schema::columns($table));
  }

  // ----------------------------------------------------------------- writing

  /**
   * Insert or update one row from request data, sent as Model[Field] fields or as
   * bare fields; anything that isn't a column is ignored. The row is updated when
   * $id (or the data's primary key) names an existing one, else inserted.
   * HABTM associations given as Alias[Alias][] (ids) replace the row's links.
   *
   * @return array|false array(ALIAS => fields): for an insert, the new row's defaults,
   *   then the fields sent, then its primary key; false when validation failed
   *   ($validationErrors) or beforeSave() refused.
   */
  public function save(array $data, $id = null) {
    $this->validationErrors = array();
    $fields = $this->fieldsFrom($data);
    $links = $this->linksFrom($data);
    if ($id === null and isset($fields[static::PRIMARY_KEY]) and $fields[static::PRIMARY_KEY] !== '') {
      $id = $fields[static::PRIMARY_KEY];
    }
    $update = ($id !== null and $this->exists($id));
    $this->id = $update ? $id : null;
    $fields = array_intersect_key($fields, $this->schema());

    if (!$this->validates($fields, $update)) return false;
    $fields = $this->beforeSave($fields, $update);
    if ($fields === false) return false;
    if (!count($fields) and !count($links)) return false;

    $schema = $this->schema();
    $names = array();
    $params = array();
    foreach ($fields as $f => $v) {
      $names[] = Db::quoteName($f);
      $params[] = self::sqlValue($schema[$f]['type'], $v);
    }
    if ($update) {
      if (count($names)) {
        $set = implode(', ', array_map(function ($n) { return "$n = ?"; }, $names));
        $params[] = $id;
        Db::execute('UPDATE '.Db::quoteName(static::TABLE)." SET $set WHERE ".Db::quoteName(static::PRIMARY_KEY).' = ?', $params);
      }
      $this->id = $id;
    } else {
      Db::execute('INSERT INTO '.Db::quoteName(static::TABLE).' ('.implode(', ', $names).') VALUES ('
        .implode(', ', array_fill(0, count($names), '?')).')', $params);
      $this->id = isset($fields[static::PRIMARY_KEY]) ? $fields[static::PRIMARY_KEY] : Db::lastInsertId();
    }
    foreach ($links as $assocAlias => $targetIds) {
      $this->replaceLinks($assocAlias, $this->id, $targetIds);
    }
    $this->afterSave($this->id, !$update);
    if ($update) return array(static::ALIAS => $fields);
    $saved = $this->createDefaults();
    foreach ($fields as $f => $v) $saved[$f] = $v;
    $saved[static::PRIMARY_KEY] = (string)$this->id;
    return array(static::ALIAS => $saved);
  }

  /**
   * The columns with a default, as a new row starts out: what an insert's result
   * lists before the fields that were sent (strings, in column order).
   */
  private function createDefaults() {
    $defaults = array();
    foreach ($this->schema() as $column => $c) {
      if ($column === static::PRIMARY_KEY or $c['default'] === null or $c['default'] === '') continue;
      if (preg_match('/^current_timestamp/i', $c['default'])) continue;
      $defaults[$column] = $c['default'];
    }
    return $defaults;
  }

  /**
   * Delete row $id, the hasMany rows marked dependent, and its HABTM links.
   * @return bool false if there is no such row or beforeDelete() refused
   */
  public function delete($id) {
    if (!$this->exists($id)) return false;
    if ($this->beforeDelete($id) === false) return false;
    foreach ($this->hasMany as $a) {
      if (empty($a['dependent'])) continue;
      $model = new $a['model']();
      foreach ($model->find('all', array('conditions' => array($model::ALIAS.'.'.$a['foreignKey'] => $id),
          'fields' => array($model::ALIAS.'.'.$model::PRIMARY_KEY))) as $child) {
        $model->delete($child[$model::ALIAS][$model::PRIMARY_KEY]);
      }
    }
    foreach ($this->habtm as $a) {
      Db::execute('DELETE FROM '.Db::quoteName($a['joinTable']).' WHERE '.Db::quoteName($a['foreignKey']).' = ?', array($id));
    }
    Db::execute('DELETE FROM '.Db::quoteName(static::TABLE).' WHERE '.Db::quoteName(static::PRIMARY_KEY).' = ?', array($id));
    return true;
  }

  /** Change or refuse (return false) the fields about to be written. */
  protected function beforeSave(array $fields, $update) {
    return $fields;
  }

  protected function afterSave($id, $created) {
  }

  /** Return false to refuse the delete. */
  protected function beforeDelete($id) {
    return true;
  }

  /** The row's fields from request data: the Model[...] group, else the top-level scalars. */
  private function fieldsFrom(array $data) {
    if (isset($data[static::ALIAS]) and is_array($data[static::ALIAS])) return $data[static::ALIAS];
    $fields = array();
    foreach ($data as $k => $v) {
      if (is_string($k) and !is_array($v)) $fields[$k] = $v;
    }
    return $fields;
  }

  /** HABTM ids in the data: Alias[Alias][] or Alias[] (or a comma separated string). */
  private function linksFrom(array $data) {
    $links = array();
    foreach ($this->habtm as $assocAlias => $a) {
      if (!array_key_exists($assocAlias, $data)) continue;
      $ids = $data[$assocAlias];
      if (is_array($ids) and array_key_exists($assocAlias, $ids)) $ids = $ids[$assocAlias];
      if (!is_array($ids)) $ids = ($ids === '' or $ids === null) ? array() : explode(',', $ids);
      $links[$assocAlias] = array_values(array_filter($ids, function ($v) { return $v !== '' and $v !== null; }));
    }
    return $links;
  }

  /** @var array alias => column => value: extra columns written into new HABTM link rows */
  public $linkValues = array();

  private function replaceLinks($assocAlias, $id, array $targetIds) {
    $a = $this->habtm[$assocAlias];
    $join = Db::quoteName($a['joinTable']);
    Db::execute("DELETE FROM $join WHERE ".Db::quoteName($a['foreignKey']).' = ?', array($id));
    $extra = isset($this->linkValues[$assocAlias]) ? $this->linkValues[$assocAlias] : array();
    $columns = array_merge(array($a['foreignKey'], $a['associationForeignKey']), array_keys($extra));
    $sql = "INSERT INTO $join (".implode(', ', array_map(array(Db::class, 'quoteName'), $columns)).') VALUES ('
      .implode(', ', array_fill(0, count($columns), '?')).')';
    foreach (array_unique($targetIds) as $target) {
      Db::execute($sql, array_merge(array($id, $target), array_values($extra)));
    }
  }

  /**
   * The value stored for a request value, by column type: NULL stays NULL;
   * TINYINT(1) stores 1 or 0; an empty string stores NULL in numeric, date,
   * time and enum columns and '' in text columns.
   */
  public static function sqlValue($type, $value) {
    if ($value === null or (is_array($value) and !count($value))) return null;
    if (is_array($value)) return json_encode($value);
    if ($type === 'tinyint(1)') {
      return ($value === true or (!is_bool($value) and !empty($value))) ? 1 : 0;
    }
    $text = preg_match('/^(var)?char|text|blob|binary|json/', $type);
    if ($value === '' and !$text) return null;
    if (is_bool($value)) return (int)$value;
    return $value;
  }

  // -------------------------------------------------------------- validation

  /** Check $fields against $validate; fills $validationErrors. */
  public function validates(array $fields, $update) {
    foreach ($this->validate as $field => $rules) {
      if (isset($rules['rule'])) $rules = array($rules); // a single rule, not a list
      foreach ($rules as $name => $rule) {
        if (isset($rule['on']) and $rule['on'] != ($update ? 'update' : 'create')) continue;
        $present = array_key_exists($field, $fields);
        if (!$present) {
          if (!empty($rule['required']) and !$update) $this->invalidate($field, $rule, $name);
          continue;
        }
        $value = $fields[$field];
        if (!empty($rule['allowEmpty']) and ($value === '' or $value === null)) continue;
        if (!$this->checkRule($rule['rule'], $field, $value, $fields)) {
          $this->invalidate($field, $rule, $name);
          if (!empty($rule['last'])) break;
        }
      }
    }
    return !count($this->validationErrors);
  }

  private function invalidate($field, array $rule, $name) {
    $this->validationErrors[$field][] = isset($rule['message']) ? $rule['message'] : (is_string($name) ? $name : 'invalid');
  }

  private function checkRule($rule, $field, $value, array $fields) {
    $args = array();
    if (is_array($rule)) {
      $args = array_slice($rule, 1);
      $rule = $rule[0];
    }
    switch ($rule) {
      case 'numeric':
        return is_numeric($value);
      case 'notBlank':
      case 'notEmpty':
        return (!empty($value) or (string)$value === '0') and preg_match('/[^\s]+/m', (string)$value);
      case 'datetime':
        // A date (year first) and a 24h time, separated by a space.
        return (bool)preg_match('/^(19|20)\d\d[-\/. ](0?[1-9]|1[012])[-\/. ](0?[1-9]|[12]\d|3[01]) ([01]?\d|2[0-3])(:[0-5]\d){0,2}$/', (string)$value);
      case 'inList':
        return in_array((string)$value, array_map('strval', $args[0]), true);
      case 'custom':
        return (bool)preg_match($args[0], (string)$value);
      case 'isUnique':
        $sql = 'SELECT COUNT(*) FROM '.Db::quoteName(static::TABLE).' WHERE '.Db::quoteName($field).' = ?';
        $params = array($value);
        if (isset($fields[static::PRIMARY_KEY])) {
          $sql .= ' AND '.Db::quoteName(static::PRIMARY_KEY).' != ?';
          $params[] = $fields[static::PRIMARY_KEY];
        } else if ($this->id !== null) {
          $sql .= ' AND '.Db::quoteName(static::PRIMARY_KEY).' != ?';
          $params[] = $this->id;
        }
        return Db::value($sql, $params) == 0;
      case 'multiple':
        return count(array_filter((array)$value, 'strlen')) > 0;
      default:
        if (method_exists($this, $rule)) return (bool)$this->$rule($value, $fields);
        return true; // an unknown rule name never rejected anything
    }
  }
}
