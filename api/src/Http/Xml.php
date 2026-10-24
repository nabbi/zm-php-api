<?php
namespace ZM\Api\Http;

/**
 * Arrays to XML for `.xml` requests, in the layout clients have received:
 * keys become elements, a list repeats its parent's element, null is an empty
 * element.
 */
class Xml {
  public static function fromArray(array $data) {
    $doc = new \DOMDocument('1.0', 'UTF-8');
    self::append($doc, $doc, $data);
    return $doc->saveXML();
  }

  private static function append(\DOMDocument $doc, \DOMNode $parent, array $data) {
    foreach ($data as $key => $value) {
      if (is_array($value) and $value and array_keys($value) === range(0, count($value) - 1)) {
        foreach ($value as $item) {
          self::element($doc, $parent, $key, $item);
        }
      } else {
        self::element($doc, $parent, $key, $value);
      }
    }
  }

  private static function element(\DOMDocument $doc, \DOMNode $parent, $key, $value) {
    // A key that isn't an XML name (a monitor Name with a space, as
    // host/getDiskPercent keys by) becomes <item name="...">.
    $valid = (!is_int($key) && preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*\z/', $key) && stripos($key, 'xml') !== 0);
    $node = $doc->createElement($valid ? $key : 'item');
    if (!is_int($key) and !$valid) $node->setAttribute('name', (string)$key);
    if (is_array($value)) {
      self::append($doc, $node, $value);
    } else if ($value !== null) {
      $node->appendChild($doc->createTextNode(is_bool($value) ? ($value ? '1' : '0') : (string)$value));
    }
    $parent->appendChild($node);
  }
}
