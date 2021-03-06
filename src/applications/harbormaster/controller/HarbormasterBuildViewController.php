<?php

final class HarbormasterBuildViewController
  extends HarbormasterController {

  public function handleRequest(AphrontRequest $request) {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $id = $request->getURIData('id');

    $build = id(new HarbormasterBuildQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->executeOne();
    if (!$build) {
      return new Aphront404Response();
    }

    require_celerity_resource('harbormaster-css');

    $title = pht('Build %d', $id);
    $warnings = array();

    $page_header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setUser($viewer)
      ->setPolicyObject($build)
      ->setHeaderIcon('fa-cubes');

    $is_restarting = $build->isRestarting();

    if ($is_restarting) {
      $page_header->setStatus(
        'fa-exclamation-triangle', 'red', pht('Restarting'));
    } else if ($build->isPausing()) {
      $page_header->setStatus(
        'fa-exclamation-triangle', 'red', pht('Pausing'));
    } else if ($build->isResuming()) {
      $page_header->setStatus(
        'fa-exclamation-triangle', 'red', pht('Resuming'));
    } else if ($build->isAborting()) {
      $page_header->setStatus(
        'fa-exclamation-triangle', 'red', pht('Aborting'));
    }

    $max_generation = (int)$build->getBuildGeneration();
    if ($max_generation === 0) {
      $min_generation = 0;
    } else {
      $min_generation = 1;
    }

    if ($is_restarting) {
      $max_generation = $max_generation + 1;
    }

    $generation = $request->getURIData('generation');
    if ($generation === null) {
      $generation = $max_generation;
    } else {
      $generation = (int)$generation;
    }

    if ($generation < $min_generation || $generation > $max_generation) {
      return new Aphront404Response();
    }

    if ($generation < $max_generation) {
      $warnings[] = pht(
        'You are viewing an older run of this build. %s',
        phutil_tag(
          'a',
          array(
            'href' => $build->getURI(),
          ),
          pht('View Current Build')));
    }


    $curtain = $this->buildCurtainView($build);
    $properties = $this->buildPropertyList($build);
    $history = $this->buildHistoryTable(
      $build,
      $generation,
      $min_generation,
      $max_generation);

    $crumbs = $this->buildApplicationCrumbs();
    $this->addBuildableCrumb($crumbs, $build->getBuildable());
    $crumbs->addTextCrumb($title);
    $crumbs->setBorder(true);

    $build_targets = id(new HarbormasterBuildTargetQuery())
      ->setViewer($viewer)
      ->needBuildSteps(true)
      ->withBuildPHIDs(array($build->getPHID()))
      ->withBuildGenerations(array($generation))
      ->execute();

    if ($build_targets) {
      $messages = id(new HarbormasterBuildMessageQuery())
        ->setViewer($viewer)
        ->withReceiverPHIDs(mpull($build_targets, 'getPHID'))
        ->execute();
      $messages = mgroup($messages, 'getReceiverPHID');
    } else {
      $messages = array();
    }

    if ($build_targets) {
      $artifacts = id(new HarbormasterBuildArtifactQuery())
        ->setViewer($viewer)
        ->withBuildTargetPHIDs(mpull($build_targets, 'getPHID'))
        ->execute();
      $artifacts = msort($artifacts, 'getArtifactKey');
      $artifacts = mgroup($artifacts, 'getBuildTargetPHID');
    } else {
      $artifacts = array();
    }


    $targets = array();
    foreach ($build_targets as $build_target) {
      $header = id(new PHUIHeaderView())
        ->setHeader($build_target->getName())
        ->setUser($viewer)
        ->setHeaderIcon('fa-bullseye');

      $target_box = id(new PHUIObjectBoxView())
        ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
        ->setHeader($header);

      $tab_group = new PHUITabGroupView();
      $target_box->addTabGroup($tab_group);

      $property_list = new PHUIPropertyListView();

      $target_artifacts = idx($artifacts, $build_target->getPHID(), array());

      $links = array();
      $type_uri = HarbormasterURIArtifact::ARTIFACTCONST;
      foreach ($target_artifacts as $artifact) {
        if ($artifact->getArtifactType() == $type_uri) {
          $impl = $artifact->getArtifactImplementation();
          if ($impl->isExternalLink()) {
            $links[] = $impl->renderLink();
          }
        }
      }

      if ($links) {
        $links = phutil_implode_html(phutil_tag('br'), $links);
        $property_list->addProperty(
          pht('External Link'),
          $links);
      }

      $status_view = new PHUIStatusListView();
      $item = new PHUIStatusItemView();

      $status = $build_target->getTargetStatus();
      $status_name =
        HarbormasterBuildTarget::getBuildTargetStatusName($status);
      $icon = HarbormasterBuildTarget::getBuildTargetStatusIcon($status);
      $color = HarbormasterBuildTarget::getBuildTargetStatusColor($status);

      $item->setTarget($status_name);
      $item->setIcon($icon, $color);
      $status_view->addItem($item);

      $when = array();
      $started = $build_target->getDateStarted();
      $now = PhabricatorTime::getNow();
      if ($started) {
        $ended = $build_target->getDateCompleted();
        if ($ended) {
          $when[] = pht(
            'Completed at %s',
            phabricator_datetime($ended, $viewer));

          $duration = ($ended - $started);
          if ($duration) {
            $when[] = pht(
              'Built for %s',
              phutil_format_relative_time_detailed($duration));
          } else {
            $when[] = pht('Built instantly');
          }
        } else {
          $when[] = pht(
            'Started at %s',
            phabricator_datetime($started, $viewer));
          $duration = ($now - $started);
          if ($duration) {
            $when[] = pht(
              'Running for %s',
              phutil_format_relative_time_detailed($duration));
          }
        }
      } else {
        $created = $build_target->getDateCreated();
        $when[] = pht(
          'Queued at %s',
          phabricator_datetime($started, $viewer));
        $duration = ($now - $created);
        if ($duration) {
          $when[] = pht(
            'Waiting for %s',
            phutil_format_relative_time_detailed($duration));
        }
      }

      $property_list->addProperty(
        pht('When'),
        phutil_implode_html(" \xC2\xB7 ", $when));

      $property_list->addProperty(pht('Status'), $status_view);

      $tab_group->addTab(
        id(new PHUITabView())
          ->setName(pht('Overview'))
          ->setKey('overview')
          ->appendChild($property_list));

      $step = $build_target->getBuildStep();

      if ($step) {
        $description = $step->getDescription();
        if ($description) {
          $description = new PHUIRemarkupView($viewer, $description);
          $property_list->addSectionHeader(
            pht('Description'), PHUIPropertyListView::ICON_SUMMARY);
          $property_list->addTextContent($description);
        }
      } else {
        $target_box->setFormErrors(
          array(
            pht(
              'This build step has since been deleted on the build plan.  '.
              'Some information may be omitted.'),
          ));
      }

      $details = $build_target->getDetails();
      $property_list = new PHUIPropertyListView();
      foreach ($details as $key => $value) {
        $property_list->addProperty($key, $value);
      }
      $tab_group->addTab(
        id(new PHUITabView())
          ->setName(pht('Configuration'))
          ->setKey('configuration')
          ->appendChild($property_list));

      $variables = $build_target->getVariables();
      $variables_tab = $this->buildProperties($variables);
      $tab_group->addTab(
        id(new PHUITabView())
          ->setName(pht('Variables'))
          ->setKey('variables')
          ->appendChild($variables_tab));

      $artifacts_tab = $this->buildArtifacts($build_target, $target_artifacts);
      $tab_group->addTab(
        id(new PHUITabView())
          ->setName(pht('Artifacts'))
          ->setKey('artifacts')
          ->appendChild($artifacts_tab));

      $build_messages = idx($messages, $build_target->getPHID(), array());
      $messages_tab = $this->buildMessages($build_messages);
      $tab_group->addTab(
        id(new PHUITabView())
          ->setName(pht('Messages'))
          ->setKey('messages')
          ->appendChild($messages_tab));

      $property_list = new PHUIPropertyListView();
      $property_list->addProperty(
        pht('Build Target ID'),
        $build_target->getID());
      $property_list->addProperty(
        pht('Build Target PHID'),
        $build_target->getPHID());

      $tab_group->addTab(
        id(new PHUITabView())
          ->setName(pht('Metadata'))
          ->setKey('metadata')
          ->appendChild($property_list));

      $targets[] = $target_box;

      $targets[] = $this->buildLog($build, $build_target);
    }

    $timeline = $this->buildTransactionTimeline(
      $build,
      new HarbormasterBuildTransactionQuery());
    $timeline->setShouldTerminate(true);

    if ($warnings) {
      $warnings = id(new PHUIInfoView())
        ->setErrors($warnings)
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING);
    } else {
      $warnings = null;
    }

    $view = id(new PHUITwoColumnView())
      ->setHeader($page_header)
      ->setCurtain($curtain)
      ->setMainColumn(
        array(
          $warnings,
          $properties,
          $history,
          $targets,
          $timeline,
        ));

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->appendChild($view);

  }

  private function buildArtifacts(
    HarbormasterBuildTarget $build_target,
    array $artifacts) {
    $viewer = $this->getViewer();

    $rows = array();
    foreach ($artifacts as $artifact) {
      $impl = $artifact->getArtifactImplementation();

      if ($impl) {
        $summary = $impl->renderArtifactSummary($viewer);
        $type_name = $impl->getArtifactTypeName();
      } else {
        $summary = pht('<Unknown Artifact Type>');
        $type_name = $artifact->getType();
      }

      $rows[] = array(
        $artifact->getArtifactKey(),
        $type_name,
        $summary,
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setNoDataString(pht('This target has no associated artifacts.'))
      ->setHeaders(
        array(
          pht('Key'),
          pht('Type'),
          pht('Summary'),
        ))
      ->setColumnClasses(
        array(
          'pri',
          '',
          'wide',
        ));

    return $table;
  }

  private function buildLog(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $request = $this->getRequest();
    $viewer = $request->getUser();
    $limit = $request->getInt('l', 25);

    $logs = id(new HarbormasterBuildLogQuery())
      ->setViewer($viewer)
      ->withBuildTargetPHIDs(array($build_target->getPHID()))
      ->execute();

    $empty_logs = array();

    $log_boxes = array();
    foreach ($logs as $log) {
      $start = 1;
      $lines = preg_split("/\r\n|\r|\n/", $log->getLogText());
      if ($limit !== 0) {
        $start = count($lines) - $limit;
        if ($start >= 1) {
          $lines = array_slice($lines, -$limit, $limit);
        } else {
          $start = 1;
        }
      }

      $id = null;
      $is_empty = false;
      if (count($lines) === 1 && trim($lines[0]) === '') {
        // Prevent Harbormaster from showing empty build logs.
        $id = celerity_generate_unique_node_id();
        $empty_logs[] = $id;
        $is_empty = true;
      }

      $log_view = new ShellLogView();
      $log_view->setLines($lines);
      $log_view->setStart($start);

      $prototype_view = id(new PHUIButtonView())
        ->setTag('a')
        ->setHref($log->getURI())
        ->setIcon('fa-file-text-o')
        ->setText(pht('New View (Prototype)'));

      $header = id(new PHUIHeaderView())
        ->setHeader(pht(
          'Build Log %d (%s - %s)',
          $log->getID(),
          $log->getLogSource(),
          $log->getLogType()))
        ->addActionLink($prototype_view)
        ->setSubheader($this->createLogHeader($build, $log))
        ->setUser($viewer);

      $log_box = id(new PHUIObjectBoxView())
        ->setHeader($header)
        ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
        ->setForm($log_view);

      if ($is_empty) {
        $log_box = phutil_tag(
          'div',
          array(
            'style' => 'display: none',
            'id' => $id,
          ),
          $log_box);
      }

      $log_boxes[] = $log_box;
    }

    if ($empty_logs) {
      $hide_id = celerity_generate_unique_node_id();

      Javelin::initBehavior('phabricator-reveal-content');

      $expand = phutil_tag(
        'div',
        array(
          'id' => $hide_id,
          'class' => 'harbormaster-empty-logs-are-hidden',
        ),
        array(
          pht(
            '%s empty logs are hidden.',
            phutil_count($empty_logs)),
          ' ',
          javelin_tag(
            'a',
            array(
              'href' => '#',
              'sigil' => 'reveal-content',
              'meta' => array(
                'showIDs' => $empty_logs,
                'hideIDs' => array($hide_id),
              ),
            ),
            pht('Show all logs.')),
        ));

      array_unshift($log_boxes, $expand);
    }

    return $log_boxes;
  }

  private function createLogHeader($build, $log) {
    $request = $this->getRequest();
    $limit = $request->getInt('l', 25);

    $lines_25 = $this->getApplicationURI('/build/'.$build->getID().'/?l=25');
    $lines_50 = $this->getApplicationURI('/build/'.$build->getID().'/?l=50');
    $lines_100 =
      $this->getApplicationURI('/build/'.$build->getID().'/?l=100');
    $lines_0 = $this->getApplicationURI('/build/'.$build->getID().'/?l=0');

    $link_25 = phutil_tag('a', array('href' => $lines_25), pht('25'));
    $link_50 = phutil_tag('a', array('href' => $lines_50), pht('50'));
    $link_100 = phutil_tag('a', array('href' => $lines_100), pht('100'));
    $link_0 = phutil_tag('a', array('href' => $lines_0), pht('Unlimited'));

    if ($limit === 25) {
      $link_25 = phutil_tag('strong', array(), $link_25);
    } else if ($limit === 50) {
      $link_50 = phutil_tag('strong', array(), $link_50);
    } else if ($limit === 100) {
      $link_100 = phutil_tag('strong', array(), $link_100);
    } else if ($limit === 0) {
      $link_0 = phutil_tag('strong', array(), $link_0);
    }

    return phutil_tag(
      'span',
      array(),
      array(
        $link_25,
        ' - ',
        $link_50,
        ' - ',
        $link_100,
        ' - ',
        $link_0,
        ' Lines',
      ));
  }

  private function buildCurtainView(HarbormasterBuild $build) {
    $viewer = $this->getViewer();
    $id = $build->getID();

    $curtain = $this->newCurtainView($build);

    $can_restart =
      $build->canRestartBuild() &&
      $build->canIssueCommand(
        $viewer,
        HarbormasterBuildCommand::COMMAND_RESTART);

    $can_pause =
      $build->canPauseBuild() &&
      $build->canIssueCommand(
        $viewer,
        HarbormasterBuildCommand::COMMAND_PAUSE);

    $can_resume =
      $build->canResumeBuild() &&
      $build->canIssueCommand(
        $viewer,
        HarbormasterBuildCommand::COMMAND_RESUME);

    $can_abort =
      $build->canAbortBuild() &&
      $build->canIssueCommand(
        $viewer,
        HarbormasterBuildCommand::COMMAND_ABORT);

    $curtain->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Restart Build'))
        ->setIcon('fa-repeat')
        ->setHref($this->getApplicationURI('/build/restart/'.$id.'/'))
        ->setDisabled(!$can_restart)
        ->setWorkflow(true));

    if ($build->canResumeBuild()) {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Resume Build'))
          ->setIcon('fa-play')
          ->setHref($this->getApplicationURI('/build/resume/'.$id.'/'))
          ->setDisabled(!$can_resume)
          ->setWorkflow(true));
    } else {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Pause Build'))
          ->setIcon('fa-pause')
          ->setHref($this->getApplicationURI('/build/pause/'.$id.'/'))
          ->setDisabled(!$can_pause)
          ->setWorkflow(true));
    }

    $curtain->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Abort Build'))
        ->setIcon('fa-exclamation-triangle')
        ->setHref($this->getApplicationURI('/build/abort/'.$id.'/'))
        ->setDisabled(!$can_abort)
        ->setWorkflow(true));

    return $curtain;
  }

  private function buildPropertyList(HarbormasterBuild $build) {
    $viewer = $this->getViewer();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer);

    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array(
        $build->getBuildablePHID(),
        $build->getBuildPlanPHID(),
      ))
      ->execute();

    $properties->addProperty(
      pht('Buildable'),
      $handles[$build->getBuildablePHID()]->renderLink());

    $properties->addProperty(
      pht('Build Plan'),
      $handles[$build->getBuildPlanPHID()]->renderLink());

    $properties->addProperty(
      pht('Status'),
      $this->getStatus($build));

    return id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Properties'))
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->appendChild($properties);

  }

  private function buildHistoryTable(
    HarbormasterBuild $build,
    $generation,
    $min_generation,
    $max_generation) {

    if ($max_generation === $min_generation) {
      return null;
    }

    $viewer = $this->getViewer();

    $uri = $build->getURI();

    $rows = array();
    $rowc = array();
    for ($ii = $max_generation; $ii >= $min_generation; $ii--) {
      if ($generation == $ii) {
        $rowc[] = 'highlighted';
      } else {
        $rowc[] = null;
      }

      $rows[] = array(
        phutil_tag(
          'a',
          array(
            'href' => $uri.$ii.'/',
          ),
          pht('Run %d', $ii)),
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setColumnClasses(
        array(
          'pri wide',
        ))
      ->setRowClasses($rowc);

    return id(new PHUIObjectBoxView())
      ->setHeaderText(pht('History'))
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->setTable($table);
  }


  private function getStatus(HarbormasterBuild $build) {
    $status_view = new PHUIStatusListView();

    $item = new PHUIStatusItemView();

    if ($build->isPausing()) {
      $status_name = pht('Pausing');
      $icon = PHUIStatusItemView::ICON_RIGHT;
      $color = 'dark';
    } else {
      $status = $build->getBuildStatus();
      $status_name =
        HarbormasterBuildStatus::getBuildStatusName($status);
      $icon = HarbormasterBuildStatus::getBuildStatusIcon($status);
      $color = HarbormasterBuildStatus::getBuildStatusColor($status);
    }

    $item->setTarget($status_name);
    $item->setIcon($icon, $color);
    $status_view->addItem($item);

    return $status_view;
  }

  private function buildMessages(array $messages) {
    $viewer = $this->getRequest()->getUser();

    if ($messages) {
      $handles = id(new PhabricatorHandleQuery())
        ->setViewer($viewer)
        ->withPHIDs(mpull($messages, 'getAuthorPHID'))
        ->execute();
    } else {
      $handles = array();
    }

    $rows = array();
    foreach ($messages as $message) {
      $rows[] = array(
        $message->getID(),
        $handles[$message->getAuthorPHID()]->renderLink(),
        $message->getType(),
        $message->getIsConsumed() ? pht('Consumed') : null,
        phabricator_datetime($message->getDateCreated(), $viewer),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setNoDataString(pht('No messages for this build target.'));
    $table->setHeaders(
      array(
        pht('ID'),
        pht('From'),
        pht('Type'),
        pht('Consumed'),
        pht('Received'),
      ));
    $table->setColumnClasses(
      array(
        '',
        '',
        'wide',
        '',
        'date',
      ));

    return $table;
  }

  private function buildProperties(array $properties) {
    ksort($properties);

    $rows = array();
    foreach ($properties as $key => $value) {
      $rows[] = array(
        $key,
        $value,
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setHeaders(
        array(
          pht('Key'),
          pht('Value'),
        ))
      ->setColumnClasses(
        array(
          'pri right',
          'wide',
        ));

    return $table;
  }

}
