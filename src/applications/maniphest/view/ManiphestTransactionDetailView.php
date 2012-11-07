<?php

/**
 * @group maniphest
 */
final class ManiphestTransactionDetailView extends ManiphestView {

  private $transactions;
  private $handles;
  private $markupEngine;
  private $forEmail;
  private $preview;
  private $commentNumber;
  private $rangeSpecification;

  private $renderSummaryOnly;
  private $renderFullSummary;
  private $user;

  private $auxiliaryFields;

  public function setAuxiliaryFields(array $fields) {
    assert_instances_of($fields, 'ManiphestAuxiliaryFieldSpecification');
    $this->auxiliaryFields = mpull($fields, null, 'getAuxiliaryKey');
    return $this;
  }

  public function getAuxiliaryField($key) {
    return idx($this->auxiliaryFields, $key);
  }

  public function setTransactionGroup(array $transactions) {
    assert_instances_of($transactions, 'ManiphestTransaction');
    $this->transactions = $transactions;
    return $this;
  }

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function setMarkupEngine(PhabricatorMarkupEngine $engine) {
    $this->markupEngine = $engine;
    return $this;
  }

  public function setPreview($preview) {
    $this->preview = $preview;
    return $this;
  }

  public function setRenderSummaryOnly($render_summary_only) {
    $this->renderSummaryOnly = $render_summary_only;
    return $this;
  }

  public function getRenderSummaryOnly() {
    return $this->renderSummaryOnly;
  }

  public function setRenderFullSummary($render_full_summary) {
    $this->renderFullSummary = $render_full_summary;
    return $this;
  }

  public function getRenderFullSummary() {
    return $this->renderFullSummary;
  }

  public function setCommentNumber($comment_number) {
    $this->commentNumber = $comment_number;
    return $this;
  }

  public function setUser(PhabricatorUser $user) {
    $this->user = $user;
    return $this;
  }

  public function setRangeSpecification($range) {
    $this->rangeSpecification = $range;
    return $this;
  }

  public function getRangeSpecification() {
    return $this->rangeSpecification;
  }

  public function renderForEmail($with_date) {
    $this->forEmail = true;

    $transaction = reset($this->transactions);
    $author = $this->renderHandles(array($transaction->getAuthorPHID()));

    $action = null;
    $descs = array();
    $comments = null;
    foreach ($this->transactions as $transaction) {
      list($verb, $desc, $classes) = $this->describeAction($transaction);
      if ($desc === null) {
        continue;
      }
      if ($action === null) {
        $action = $verb;
      }
      $desc = $author.' '.$desc.'.';
      if ($with_date) {
        // NOTE: This is going into a (potentially multi-recipient) email so
        // we can't use a single user's timezone preferences. Use the server's
        // instead, but make the timezone explicit.
        $datetime = date('M jS \a\t g:i A T', $transaction->getDateCreated());
        $desc = "On {$datetime}, {$desc}";
      }
      $descs[] = $desc;
      if ($transaction->hasComments()) {
        $comments = $transaction->getComments();
      }
    }

    $descs = implode("\n", $descs);

    if ($comments) {
      $descs = $comments."\n\n====\n\n".$descs;
    }

    foreach ($this->transactions as $transaction) {
      $supplemental = $this->renderSupplementalInfoForEmail($transaction);
      if ($supplemental) {
        $descs .= "\n".$supplemental;
      }
    }

    $this->forEmail = false;
    return array($action, $descs);
  }

  public function render() {

    if (!$this->user) {
      throw new Exception("Call setUser() before render()!");
    }

    $handles = $this->handles;
    $transactions = $this->transactions;

    require_celerity_resource('maniphest-transaction-detail-css');

    $comment_transaction = null;
    foreach ($this->transactions as $transaction) {
      if ($transaction->hasComments()) {
        $comment_transaction = $transaction;
        break;
      }
    }
    $any_transaction = reset($transactions);

    $author = $this->handles[$any_transaction->getAuthorPHID()];

    $more_classes = array();
    $descs = array();
    foreach ($transactions as $transaction) {
      list($verb, $desc, $classes) = $this->describeAction($transaction);
      if ($desc === null) {
        continue;
      }
      $more_classes = array_merge($more_classes, $classes);
      $full_summary = null;
      if ($this->getRenderFullSummary()) {
        $full_summary = $this->renderFullSummary($transaction);
      }
      $descs[] = javelin_render_tag(
        'div',
        array(
          'sigil' => 'maniphest-transaction-description',
        ),
        $author->renderLink().' '.$desc.'.'.$full_summary);
    }

    if ($this->getRenderSummaryOnly()) {
      return implode("\n", $descs);
    }

    if ($comment_transaction && $comment_transaction->hasComments()) {
      $comment_block = $this->markupEngine->getOutput(
        $comment_transaction,
        ManiphestTransaction::MARKUP_FIELD_BODY);
      $comment_block =
        '<div class="maniphest-transaction-comments phabricator-remarkup">'.
          $comment_block.
        '</div>';
    } else {
      $comment_block = null;
    }

    $source_transaction = nonempty($comment_transaction, $any_transaction);

    $xaction_view = id(new PhabricatorTransactionView())
      ->setUser($this->user)
      ->setImageURI($author->getImageURI())
      ->setContentSource($source_transaction->getContentSource())
      ->setActions($descs);

    foreach ($more_classes as $class) {
      $xaction_view->addClass($class);
    }

    if ($this->preview) {
      $xaction_view->setIsPreview($this->preview);
    } else {
      $xaction_view->setEpoch($any_transaction->getDateCreated());
      if ($this->commentNumber) {
        $anchor_name = 'comment-'.$this->commentNumber;
        $anchor_text = 'T'.$any_transaction->getTaskID().'#'.$anchor_name;

        $xaction_view->setAnchor($anchor_name, $anchor_text);
      }
    }

    $xaction_view->appendChild($comment_block);

    return $xaction_view->render();
  }

  private function renderSupplementalInfoForEmail($transaction) {
    $handles = $this->handles;

    $type = $transaction->getTransactionType();
    $new = $transaction->getNewValue();
    $old = $transaction->getOldValue();

    switch ($type) {
      case ManiphestTransactionType::TYPE_DESCRIPTION:
        return "NEW DESCRIPTION\n  ".trim($new)."\n\n".
               "PREVIOUS DESCRIPTION\n  ".trim($old);
      case ManiphestTransactionType::TYPE_ATTACH:
        $old_raw = nonempty($old, array());
        $new_raw = nonempty($new, array());

        $attach_types = array(
          PhabricatorPHIDConstants::PHID_TYPE_DREV,
          PhabricatorPHIDConstants::PHID_TYPE_FILE,
        );

        foreach ($attach_types as $attach_type) {
          $old = array_keys(idx($old_raw, $attach_type, array()));
          $new = array_keys(idx($new_raw, $attach_type, array()));
          if ($old != $new) {
            break;
          }
        }

        $added = array_diff($new, $old);
        if (!$added) {
          break;
        }

        $links = array();
        foreach (array_select_keys($handles, $added) as $handle) {
          $links[] = '  '.PhabricatorEnv::getProductionURI($handle->getURI());
        }
        $links = implode("\n", $links);

        switch ($attach_type) {
          case PhabricatorPHIDConstants::PHID_TYPE_DREV:
            $title = 'ATTACHED REVISIONS';
            break;
          case PhabricatorPHIDConstants::PHID_TYPE_FILE:
            $title = 'ATTACHED FILES';
            break;
        }

        return $title."\n".$links;
      case ManiphestTransactionType::TYPE_EDGE:
        $add = array_diff_key($new, $old);
        if (!$add) {
          break;
        }

        $links = array();
        foreach ($add as $phid => $ignored) {
          $handle = $handles[$phid];
          $links[] = '  '.PhabricatorEnv::getProductionURI($handle->getURI());
        }
        $links = implode("\n", $links);

        $edge_type = $transaction->getMetadataValue('edge:type');
        $title = $this->getEdgeEmailTitle($edge_type, $add);

        return $title."\n".$links;
      default:
        break;
    }

    return null;
  }

  private function describeAction($transaction) {
    $verb = null;
    $desc = null;
    $classes = array();

    $handles = $this->handles;

    $type = $transaction->getTransactionType();
    $author_phid = $transaction->getAuthorPHID();
    $new = $transaction->getNewValue();
    $old = $transaction->getOldValue();
    switch ($type) {
      case ManiphestTransactionType::TYPE_TITLE:
        $verb = pht('Retitled');
        $desc = pht('changed the title from %s to %s', $this->renderString($old),
          $this->renderString($new));
        break;
      case ManiphestTransactionType::TYPE_DESCRIPTION:
        $verb = pht('Edited');
        if ($this->forEmail || $this->getRenderFullSummary()) {
          $desc = pht('updated the task description');
        } else {
          $desc = pht('updated the task description').' '.
                  $this->renderExpandLink($transaction);
        }
        break;
      case ManiphestTransactionType::TYPE_NONE:
        $verb = pht('Commented On');
        $desc = pht('added a comment');
        break;
      case ManiphestTransactionType::TYPE_OWNER:
        if ($transaction->getAuthorPHID() == $new) {
          $verb = pht('Claimed');
          $desc = pht('claimed this task');
          $classes[] = 'claimed';
        } else if (!$new) {
          $verb = pht('Up For Grabs');
          $desc = pht('placed this task up for grabs');
          $classes[] = 'upforgrab';
        } else if (!$old) {
          $verb = pht('Assigned');
          $desc = pht('assigned this task to %s', $this->renderHandles(array($new)));
          $classes[] = 'assigned';
        } else {
          $verb = pht('Reassigned');
          $desc = pht('reassigned this task from %s to %s',
            $this->renderHandles(array($old)),
            $this->renderHandles(array($new)));
          $classes[] = 'reassigned';
        }
        break;
      case ManiphestTransactionType::TYPE_CCS:
        $added   = array_diff($new, $old);
        $removed = array_diff($old, $new);
        // can only add in preview so just show placeholder if nothing to add
        if ($this->preview && empty($added)) {
          $verb = pht('Changed CC');
          $desc = pht('changed CCs..');
          break;
        }
        if ($added && !$removed) {
          $verb = pht('Added CC');
          if (count($added) == 1) {
            $desc = pht('added %s to CC', $this->renderHandles($added));
          } else {
            $desc = pht('added CCs: %s', $this->renderHandles($added));
          }
        } else if ($removed && !$added) {
          $verb = pht('Removed CC');
          if (count($removed) == 1) {
            $desc = pht('removed %s from CC', $this->renderHandles($removed));
          } else {
            $desc = pht('removed CCs: %s', $this->renderHandles($removed));
          }
        } else {
          $verb = pht('Changed CC');
          $desc = pht('changed CCs, added: %s;  removed: %s', $this->renderHandles($removed), $this->renderHandles($added));
        }
        break;
      case ManiphestTransactionType::TYPE_EDGE:
        $edge_type = $transaction->getMetadataValue('edge:type');

        $add = array_diff_key($new, $old);
        $rem = array_diff_key($old, $new);

        if ($add && !$rem) {
          $verb = $this->getEdgeAddVerb($edge_type);
          $desc = $this->getEdgeAddList($edge_type, $add);
        } else if ($rem && !$add) {
          $verb = $this->getEdgeRemVerb($edge_type);
          $desc = $this->getEdgeRemList($edge_type, $rem);
        } else {
          $verb = $this->getEdgeEditVerb($edge_type);
          $desc = $this->getEdgeEditList($edge_type, $add, $rem);
        }
        break;
      case ManiphestTransactionType::TYPE_PROJECTS:
        $added   = array_diff($new, $old);
        $removed = array_diff($old, $new);
        // can only add in preview so just show placeholder if nothing to add
        if ($this->preview && empty($added)) {
          $verb = pht('Changed Projects');
          $desc = pht('changed projects..');
          break;
        }
        if ($added && !$removed) {
          $verb = pht('Added Project');
          if (count($added) == 1) {
            $desc = pht('added project %s', $this->renderHandles($added));
          } else {
            $desc = pht('added projects: %s', $this->renderHandles($added));
          }
        } else if ($removed && !$added) {
          $verb = pht('Removed Project');
          if (count($removed) == 1) {
            $desc = pht('removed project %s', $this->renderHandles($removed));
          } else {
            $desc = pht('removed projects: %s', $this->renderHandles($removed));
          }
        } else {
          $verb = pht('Changed Projects');
          $desc = pht('changed projects, added: %s; removed: %s',
            $this->renderHandles($added), $this->renderHandles($removed));
        }
        break;
      case ManiphestTransactionType::TYPE_STATUS:
        if ($new == ManiphestTaskStatus::STATUS_OPEN) {
          if ($old) {
            $verb = pht('Reopened');
            $desc = pht('reopened this task');
            $classes[] = 'reopened';
          } else {
            $verb = pht('Created');
            $desc = pht('created this task');
            $classes[] = 'created';
          }
        } else if ($new == ManiphestTaskStatus::STATUS_CLOSED_SPITE) {
          $verb = pht('Spited');
          $desc = pht('closed this task out of spite');
          $classes[] = 'spited';
        } else if ($new == ManiphestTaskStatus::STATUS_CLOSED_DUPLICATE) {
          $verb = pht('Merged');
          $desc = pht('closed this task as a duplicate');
          $classes[] = 'duplicate';
        } else {
          $verb = pht('Closed');
          $full = idx(ManiphestTaskStatus::getTaskStatusMap(), $new, '???');
          $desc = pht('closed this task as "%s"', $full);
          $classes[] = 'closed';
        }
        break;
      case ManiphestTransactionType::TYPE_PRIORITY:
        $old_name = ManiphestTaskPriority::getTaskPriorityName($old);
        $new_name = ManiphestTaskPriority::getTaskPriorityName($new);

        if ($old == ManiphestTaskPriority::PRIORITY_TRIAGE) {
          $verb = pht('Triaged');
          $desc = pht('triaged this task as "%s" priority', $new_name);
        } else if ($old > $new) {
          $verb = pht('Lowered Priority');
          $desc = pht('lowered the priority of this task from "%s" to "%s"',
            $old_name, $new_name);
        } else {
          $verb = pht('Raised Priority');
          $desc = pht('raised the priority of this task from "%s" to "%s"',
            $old_name, $new_name);
        }
        if ($new == ManiphestTaskPriority::PRIORITY_UNBREAK_NOW) {
          $classes[] = 'unbreaknow';
        }
        break;
      case ManiphestTransactionType::TYPE_ATTACH:
        if ($this->preview) {
          $verb = pht('Changed Attached');
          $desc = pht('changed attachments..');
          break;
        }

        $old_raw = nonempty($old, array());
        $new_raw = nonempty($new, array());

        foreach (array(
          PhabricatorPHIDConstants::PHID_TYPE_DREV,
          PhabricatorPHIDConstants::PHID_TYPE_TASK,
          PhabricatorPHIDConstants::PHID_TYPE_FILE) as $attach_type) {
          $old = array_keys(idx($old_raw, $attach_type, array()));
          $new = array_keys(idx($new_raw, $attach_type, array()));
          if ($old != $new) {
            break;
          }
        }

        $added = array_diff($new, $old);
        $removed = array_diff($old, $new);

        $add_desc = $this->renderHandles($added);
        $rem_desc = $this->renderHandles($removed);

        if ($added && !$removed) {
          $verb = pht('Attached');
          $desc =
            pht('attached %s: %s',
              $this->getAttachName($attach_type, count($added)), $add_desc);
        } else if ($removed && !$added) {
          $verb = pht('Detached');
          $desc =
            pht('detached %s: %s',
              $this->getAttachName($attach_type, count($removed)), $rem_desc);
        } else {
          $verb = pht('Changed Attached');
          $desc =
            pht('changed attached %s, added: %s; removed: %s',
              $this->getAttachName($attach_type, count($added) + count($removed)),
              $add_desc,
              $rem_desc);
        }
        break;
      case ManiphestTransactionType::TYPE_AUXILIARY:
        $aux_key = $transaction->getMetadataValue('aux:key');
        $aux_field = $this->getAuxiliaryField($aux_key);

        $verb = null;
        if ($aux_field) {
          $verb = $aux_field->renderTransactionEmailVerb($transaction);
        }
        if ($verb === null) {
          if ($old === null) {
            $verb = pht('Set Field');
          } else if ($new === null) {
            $verb = pht('Removed Field');
          } else {
            $verb = pht('Updated Field');
          }
        }

        $desc = null;
        if ($aux_field) {
          $use_field = $aux_field;
        } else {
          $use_field = id(new ManiphestAuxiliaryFieldDefaultSpecification())
            ->setFieldType(
              ManiphestAuxiliaryFieldDefaultSpecification::TYPE_STRING);
        }

        $desc = $use_field->renderTransactionDescription(
          $transaction,
          $this->forEmail
            ? ManiphestAuxiliaryFieldSpecification::RENDER_TARGET_TEXT
            : ManiphestAuxiliaryFieldSpecification::RENDER_TARGET_HTML);

        break;
      default:
        return array($type, ' brazenly '.$type."'d", $classes);
    }

    return array($verb, $desc, $classes);
  }

  private function renderFullSummary($transaction) {
    switch ($transaction->getTransactionType()) {
      case ManiphestTransactionType::TYPE_DESCRIPTION:
        $id = $transaction->getID();

        $old_text = wordwrap($transaction->getOldValue(), 80);
        $new_text = wordwrap($transaction->getNewValue(), 80);

        $engine = new PhabricatorDifferenceEngine();
        $changeset = $engine->generateChangesetFromFileContent($old_text,
                                                               $new_text);

        $whitespace_mode = DifferentialChangesetParser::WHITESPACE_SHOW_ALL;

        $parser = new DifferentialChangesetParser();
        $parser->setChangeset($changeset);
        $parser->setRenderingReference($id);
        $parser->setWhitespaceMode($whitespace_mode);

        $spec = $this->getRangeSpecification();
        list($range_s, $range_e, $mask) =
          DifferentialChangesetParser::parseRangeSpecification($spec);
        $output = $parser->render($range_s, $range_e, $mask);

        return $output;
    }

    return null;
  }

  private function renderExpandLink($transaction) {
    $id = $transaction->getID();

    Javelin::initBehavior('maniphest-transaction-expand');

    return javelin_render_tag(
      'a',
      array(
        'href'          => '/maniphest/task/descriptionchange/'.$id.'/',
        'sigil'         => 'maniphest-expand-transaction',
        'mustcapture'   => true,
      ),
      'show details');
  }

  private function renderHandles($phids, $full = true) {
    $links = array();
    foreach ($phids as $phid) {
      if ($this->forEmail) {
        if ($full) {
          $links[] = $this->handles[$phid]->getFullName();
        } else {
          $links[] = $this->handles[$phid]->getName();
        }
      } else {
        $links[] = $this->handles[$phid]->renderLink();
      }
    }
    return implode(', ', $links);
  }

  private function renderString($string) {
    if ($this->forEmail) {
      return '"'.$string.'"';
    } else {
      return '"'.phutil_escape_html($string).'"';
    }
  }


/* -(  Strings  )------------------------------------------------------------ */


  /**
   * @task strings
   */
  private function getAttachName($attach_type, $count) {
    switch ($attach_type) {
      case PhabricatorPHIDConstants::PHID_TYPE_DREV:
        return pht('Differential Revision(s)', $count);
      case PhabricatorPHIDConstants::PHID_TYPE_FILE:
        return pht('file(s)', $count);
      case PhabricatorPHIDConstants::PHID_TYPE_TASK:
        return pht('Maniphest Task(s)', $count);
    }
  }


  /**
   * @task strings
   */
  private function getEdgeEmailTitle($type, array $list) {
    $count = count($list);
    switch ($type) {
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_RELATED_DREV:
        return pht('DIFFERENTIAL %d REVISION(S)', $count);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK:
        return pht('DEPENDS ON %d TASK(S)', $count);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDED_ON_BY_TASK:
        return pht('DEPENDENT %d TASK(s)', $count);
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_COMMIT:
        return pht('ATTACHED %d COMMIT(S)', $count);
      default:
        return pht('ATTACHED %d OBJECT(S)', $count);
    }
  }


  /**
   * @task strings
   */
  private function getEdgeAddVerb($type) {
    switch ($type) {
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_RELATED_DREV:
        return pht('Added Revision');
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK:
        return pht('Added Dependency');
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDED_ON_BY_TASK:
        return pht('Added Dependent Task');
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_COMMIT:
        return pht('Added Commit');
      default:
        return pht('Added Object');
    }
  }


  /**
   * @task strings
   */
  private function getEdgeRemVerb($type) {
    switch ($type) {
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_RELATED_DREV:
        return pht('Removed Revision');
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK:
        return pht('Removed Dependency');
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDED_ON_BY_TASK:
        return pht('Removed Dependent Task');
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_COMMIT:
        return pht('Removed Commit');
      default:
        return pht('Removed Object');
    }
  }


  /**
   * @task strings
   */
  private function getEdgeEditVerb($type) {
    switch ($type) {
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_RELATED_DREV:
        return pht('Changed Revisions');
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK:
        return pht('Changed Dependencies');
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDED_ON_BY_TASK:
        return pht('Changed Dependent Tasks');
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_COMMIT:
        return pht('Changed Commits');
      default:
        return pht('Changed Objects');
    }
  }


  /**
   * @task strings
   */
  private function getEdgeAddList($type, array $add) {
    $list = $this->renderHandles(array_keys($add), $full = true);
    $count = count($add);

    switch ($type) {
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_RELATED_DREV:
        return pht('added %d revision(s): %s', $count, $list);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK:
        return pht('added %d dependencie(s): %s', $count, $list);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDED_ON_BY_TASK:
        return pht('added %d dependent task(s): %s', $count, $list);
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_COMMIT:
        return pht('added %d commit(s): %s', $count, $list);
      default:
        return pht('added %d object(s): %s', $count, $list);
    }
  }


  /**
   * @task strings
   */
  private function getEdgeRemList($type, array $rem) {
    $list = $this->renderHandles(array_keys($rem), $full = true);
    $count = count($rem);

    switch ($type) {
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_RELATED_DREV:
        return pht('removed %d revision(s): %s', $count, $list);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK:
        return pht('removed %d dependencie(s): %s', $count, $list);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDED_ON_BY_TASK:
        return pht('removed %d dependent task(s): %s', $count, $list);
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_COMMIT:
        return pht('removed %d commit(s): %s', $count, $list);
      default:
        return pht('removed %d object(s): %s', $count, $list);
    }
  }


  /**
   * @task strings
   */
  private function getEdgeEditList($type, array $add, array $rem) {
    $add_list = $this->renderHandles(array_keys($add), $full = true);
    $rem_list = $this->renderHandles(array_keys($rem), $full = true);
    $add_count = count($add_list);
    $rem_count = count($rem_list);

    switch ($type) {
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_RELATED_DREV:
        return pht(
          'changed %d revision(s), added %d: %s; removed %d: %s',
          $add_count + $rem_count,
          $add_count,
          $add_list,
          $rem_count,
          $rem_list);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDS_ON_TASK:
        return pht(
          'changed %d dependencie(s), added %d: %s; removed %d: %s',
          $add_count + $rem_count,
          $add_count,
          $add_list,
          $rem_count,
          $rem_list);
      case PhabricatorEdgeConfig::TYPE_TASK_DEPENDED_ON_BY_TASK:
        return pht(
          'changed %d dependent task(s), added %d: %s; removed %d: %s',
          $add_count + $rem_count,
          $add_count,
          $add_list,
          $rem_count,
          $rem_list);
      case PhabricatorEdgeConfig::TYPE_TASK_HAS_COMMIT:
        return pht(
          'changed %d commit(s), added %d: %s; removed %d: %s',
          $add_count + $rem_count,
          $add_count,
          $add_list,
          $rem_count,
          $rem_list);
      default:
        return pht(
          'changed %d object(s), added %d: %s; removed %d: %s',
          $add_count + $rem_count,
          $add_count,
          $add_list,
          $rem_count,
          $rem_list);
    }
  }

}
