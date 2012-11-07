<?php

final class AphrontHeadsupView extends AphrontView {

  private $actionList;
  private $header;
  private $properties;
  private $objectName;
  private $hasKeyboardShortcuts;

  public function setActionList(AphrontHeadsupActionListView $action_list) {
    $this->actionList = $action_list;
    return $this;
  }

  public function setHeader($header) {
    $this->header = $header;
    return $this;
  }

  public function setProperties(array $dict) {
    $this->properties = $dict;
    return $this;
  }

  public function setObjectName($name) {
    $this->objectName = $name;
    return $this;
  }

  public function setHasKeyboardShortcuts($has_keyboard_shortcuts) {
    $this->hasKeyboardShortcuts = $has_keyboard_shortcuts;
    return $this;
  }

  public function getHasKeyboardShortcuts() {
    return $this->hasKeyboardShortcuts;
  }

  public function render() {
    $header =
      '<h1>'.
        phutil_render_tag(
          'a',
          array(
            'href' => '/'.$this->objectName,
            'class' => 'aphront-headsup-object-name',
          ),
          phutil_escape_html($this->objectName)).
        ' '.
        phutil_escape_html($this->header).
      '</h1>';

    require_celerity_resource('aphront-headsup-view-css');

    $shortcuts = null;
    if ($this->hasKeyboardShortcuts) {
      $shortcuts =
        '<div class="aphront-headsup-keyboard-shortcuts">'.
          id(new AphrontKeyboardShortcutsAvailableView())->render().
        '</div>';
    }

    $prop_table = null;
    if ($this->properties) {
      $prop_table = array();
      foreach ($this->properties as $key => $value) {
        $prop_table[] =
          '<tr>'.
            '<th>'.phutil_escape_html($key.':').'</th>'.
            '<td>'.$value.'</td>'.
          '</tr>';
      }
      $prop_table =
        '<table class="aphront-headsup-property-table">'.
          implode("\n", $prop_table).
        '</table>';
    }

    $children = $this->renderChildren();
    if (strlen($children)) {
      $children =
        '<div class="aphront-headsup-details">'.
          $children.
        '</div>';
    }

    return
      '<div class="aphront-headsup-panel">'.
        '<div class="right-ct">'.
        $shortcuts.
        self::renderSingleView($this->actionList).
        '</div>'.
        '<div class="aphront-headsup-core">'.
          $header.
          $prop_table.
          $children.
        '</div>'.
      '</div>';
  }

}
