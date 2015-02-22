<?php

abstract class PhabricatorDaemonManagementWorkflow
  extends PhabricatorManagementWorkflow {

  private $runDaemonsAsUser = null;

  protected final function loadAvailableDaemonClasses() {
    $loader = new PhutilSymbolLoader();
    return $loader
      ->setAncestorClass('PhutilDaemon')
      ->setConcreteOnly(true)
      ->selectSymbolsWithoutLoading();
  }

  protected final function getPIDDirectory() {
    $path = PhabricatorEnv::getEnvConfig('phd.pid-directory');
    return $this->getControlDirectory($path);
  }

  protected final function getLogDirectory() {
    $path = PhabricatorEnv::getEnvConfig('phd.log-directory');
    return $this->getControlDirectory($path);
  }

  private function getControlDirectory($path) {
    if (!Filesystem::pathExists($path)) {
      list($err) = exec_manual('mkdir -p %s', $path);
      if ($err) {
        throw new Exception(
          "phd requires the directory '{$path}' to exist, but it does not ".
          "exist and could not be created. Create this directory or update ".
          "'phd.pid-directory' / 'phd.log-directory' in your configuration ".
          "to point to an existing directory.");
      }
    }
    return $path;
  }

  protected final function loadRunningDaemons() {
    $daemons = array();

    $pid_dir = $this->getPIDDirectory();
    $pid_files = Filesystem::listDirectory($pid_dir);

    foreach ($pid_files as $pid_file) {
      $path = $pid_dir.'/'.$pid_file;
      $daemons[] = PhabricatorDaemonReference::loadReferencesFromFile($path);
    }

    return array_mergev($daemons);
  }

  protected final function loadAllRunningDaemons() {
    $local_daemons = $this->loadRunningDaemons();

    $local_ids = array();
    foreach ($local_daemons as $daemon) {
      $daemon_log = $daemon->getDaemonLog();

      if ($daemon_log) {
        $local_ids[] = $daemon_log->getID();
      }
    }

    $remote_daemons = id(new PhabricatorDaemonLogQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withoutIDs($local_ids)
      ->withStatus(PhabricatorDaemonLogQuery::STATUS_ALIVE)
      ->execute();

    return array_merge($local_daemons, $remote_daemons);
  }

  private function findDaemonClass($substring) {
    $symbols = $this->loadAvailableDaemonClasses();

    $symbols = ipull($symbols, 'name');
    $match = array();
    foreach ($symbols as $symbol) {
      if (stripos($symbol, $substring) !== false) {
        if (strtolower($symbol) == strtolower($substring)) {
          $match = array($symbol);
          break;
        } else {
          $match[] = $symbol;
        }
      }
    }

    if (count($match) == 0) {
      throw new PhutilArgumentUsageException(
        pht(
          "No daemons match '%s'! Use 'phd list' for a list of available ".
          "daemons.",
          $substring));
    } else if (count($match) > 1) {
      throw new PhutilArgumentUsageException(
        pht(
          "Specify a daemon unambiguously. Multiple daemons match '%s': %s.",
          $substring,
          implode(', ', $match)));
    }

    return head($match);
  }

  protected final function launchDaemon(
    $class,
    array $argv,
    $debug,
    $run_as_current_user = false) {

    $daemon = $this->findDaemonClass($class);
    $console = PhutilConsole::getConsole();

    if (!$run_as_current_user) {
      // Check if the script is started as the correct user
      $phd_user = PhabricatorEnv::getEnvConfig('phd.user');
      $current_user = posix_getpwuid(posix_geteuid());
      $current_user = $current_user['name'];
      if ($phd_user && $phd_user != $current_user) {
        if ($debug) {
          throw new PhutilArgumentUsageException(pht(
            'You are trying to run a daemon as a nonstandard user, '.
            'and `phd` was not able to `sudo` to the correct user. '."\n".
            'Phabricator is configured to run daemons as "%s", '.
            'but the current user is "%s". '."\n".
            'Use `sudo` to run as a different user, pass `--as-current-user` '.
            'to ignore this warning, or edit `phd.user` '.
            'to change the configuration.', $phd_user, $current_user));
        } else {
          $this->runDaemonsAsUser = $phd_user;
          $console->writeOut(pht('Starting daemons as %s', $phd_user)."\n");
        }
      }
    }

    if ($debug) {
      if ($argv) {
        $console->writeOut(
          pht(
            "Launching daemon \"%s\" in debug mode (not daemonized) ".
            "with arguments %s.\n",
            $daemon,
            csprintf('%LR', $argv)));
      } else {
        $console->writeOut(
          pht(
            "Launching daemon \"%s\" in debug mode (not daemonized).\n",
            $daemon));
      }
    } else {
      if ($argv) {
        $console->writeOut(
          pht(
            "Launching daemon \"%s\" with arguments %s.\n",
            $daemon,
            csprintf('%LR', $argv)));
      } else {
        $console->writeOut(
          pht(
            "Launching daemon \"%s\".\n",
            $daemon));
      }
    }

    $flags = array();
    if ($debug || PhabricatorEnv::getEnvConfig('phd.trace')) {
      $flags[] = '--trace';
    }

    if ($debug || PhabricatorEnv::getEnvConfig('phd.verbose')) {
      $flags[] = '--verbose';
    }

    $config = array();

    if (!$debug) {
      $config['daemonize'] = true;
    }

    if (!$debug) {
      $config['log'] = $this->getLogDirectory().'/daemons.log';
    }

    $pid_dir = $this->getPIDDirectory();

    // TODO: This should be a much better user experience.
    Filesystem::assertExists($pid_dir);
    Filesystem::assertIsDirectory($pid_dir);
    Filesystem::assertWritable($pid_dir);

    $config['piddir'] = $pid_dir;

    $config['daemons'] = array(
      array(
        'class' => $daemon,
        'argv' => $argv,
      ),
    );

    $command = csprintf('./phd-daemon %Ls', $flags);

    $phabricator_root = dirname(phutil_get_library_root('phabricator'));
    $daemon_script_dir = $phabricator_root.'/scripts/daemon/';

    if ($debug) {
      // Don't terminate when the user sends ^C; it will be sent to the
      // subprocess which will terminate normally.
      pcntl_signal(
        SIGINT,
        array(__CLASS__, 'ignoreSignal'));

      echo "\n    phabricator/scripts/daemon/ \$ {$command}\n\n";

      $tempfile = new TempFile('daemon.config');
      Filesystem::writeFile($tempfile, json_encode($config));

      phutil_passthru(
        '(cd %s && exec %C < %s)',
        $daemon_script_dir,
        $command,
        $tempfile);
    } else {
      try {
        $this->executeDaemonLaunchCommand(
          $command,
          $daemon_script_dir,
          $config,
          $this->runDaemonsAsUser);
      } catch (Exception $e) {
        // Retry without sudo
        $console->writeOut(pht(
          "sudo command failed. Starting daemon as current user\n"));
        $this->executeDaemonLaunchCommand(
          $command,
          $daemon_script_dir,
          $config);
      }
    }
  }

  private function executeDaemonLaunchCommand(
    $command,
    $daemon_script_dir,
    array $config,
    $run_as_user = null) {

    $is_sudo = false;
    if ($run_as_user) {
      // If anything else besides sudo should be
      // supported then insert it here (runuser, su, ...)
      $command = csprintf(
        'sudo -En -u %s -- %C',
        $run_as_user,
        $command);
      $is_sudo = true;
    }
    $future = new ExecFuture('exec %C', $command);
    // Play games to keep 'ps' looking reasonable.
    $future->setCWD($daemon_script_dir);
    $future->write(json_encode($config));
    list($stdout, $stderr) = $future->resolvex();

    if ($is_sudo) {
      // On OSX, `sudo -n` exits 0 when the user does not have permission to
      // switch accounts without a password. This is not consistent with
      // sudo on Linux, and seems buggy/broken. Check for this by string
      // matching the output.
      if (preg_match('/sudo: a password is required/', $stderr)) {
        throw new Exception(
          pht(
            'sudo exited with a zero exit code, but emitted output '.
            'consistent with failure under OSX.'));
      }
    }
  }

  public static function ignoreSignal($signo) {
    return;
  }

  public static function requireExtensions() {
    self::mustHaveExtension('pcntl');
    self::mustHaveExtension('posix');
  }

  private static function mustHaveExtension($ext) {
    if (!extension_loaded($ext)) {
      echo "ERROR: The PHP extension '{$ext}' is not installed. You must ".
           "install it to run daemons on this machine.\n";
      exit(1);
    }

    $extension = new ReflectionExtension($ext);
    foreach ($extension->getFunctions() as $function) {
      $function = $function->name;
      if (!function_exists($function)) {
        echo "ERROR: The PHP function {$function}() is disabled. You must ".
             "enable it to run daemons on this machine.\n";
        exit(1);
      }
    }
  }

  protected final function willLaunchDaemons() {
    $console = PhutilConsole::getConsole();
    $console->writeErr(pht('Preparing to launch daemons.')."\n");

    $log_dir = $this->getLogDirectory().'/daemons.log';
    $console->writeErr(pht("NOTE: Logs will appear in '%s'.", $log_dir)."\n\n");
  }


/* -(  Commands  )----------------------------------------------------------- */


  protected final function executeStartCommand($keep_leases = false) {
    $console = PhutilConsole::getConsole();

    $running = $this->loadRunningDaemons();

    // This may include daemons which were launched but which are no longer
    // running; check that we actually have active daemons before failing.
    foreach ($running as $daemon) {
      if ($daemon->isRunning()) {
        $message = pht(
          "phd start: Unable to start daemons because daemons are already ".
          "running.\n".
          "You can view running daemons with 'phd status'.\n".
          "You can stop running daemons with 'phd stop'.\n".
          "You can use 'phd restart' to stop all daemons before starting new ".
          "daemons.");

        $console->writeErr("%s\n", $message);
        exit(1);
      }
    }

    if ($keep_leases) {
      $console->writeErr("%s\n", pht('Not touching active task queue leases.'));
    } else {
      $console->writeErr("%s\n", pht('Freeing active task leases...'));
      $count = $this->freeActiveLeases();
      $console->writeErr(
        "%s\n",
        pht('Freed %s task lease(s).', new PhutilNumber($count)));
    }

    $daemons = array(
      array('PhabricatorRepositoryPullLocalDaemon', array()),
      array('PhabricatorGarbageCollectorDaemon', array()),
      array('PhabricatorTriggerDaemon', array()),
    );

    $taskmasters = PhabricatorEnv::getEnvConfig('phd.start-taskmasters');
    for ($ii = 0; $ii < $taskmasters; $ii++) {
      $daemons[] = array('PhabricatorTaskmasterDaemon', array());
    }

    $this->willLaunchDaemons();

    foreach ($daemons as $spec) {
      list($name, $argv) = $spec;
      $this->launchDaemon($name, $argv, $is_debug = false);
    }

    $console->writeErr(pht('Done.')."\n");
    return 0;
  }

  protected final function executeStopCommand(
    array $pids,
    array $options) {

    $console = PhutilConsole::getConsole();

    $grace_period = idx($options, 'graceful', 15);
    $force = idx($options, 'force');
    $gently = idx($options, 'gently');

    if ($gently && $force) {
      throw new PhutilArgumentUsageException(
        pht(
          'You can not specify conflicting options --gently and --force '.
          'together.'));
    }

    $daemons = $this->loadRunningDaemons();
    if (!$daemons) {
      $survivors = array();
      if (!$pids && !$gently) {
        $survivors = $this->processRogueDaemons(
          $grace_period,
          $warn = true,
          $force);
      }
      if (!$survivors) {
        $console->writeErr(pht(
          'There are no running Phabricator daemons.')."\n");
      }
      return 0;
    }

    $running_pids = array_fuse(mpull($daemons, 'getPID'));
    if (!$pids) {
      $stop_pids = $running_pids;
    } else {
      // We were given a PID or set of PIDs to kill.
      $stop_pids = array();
      foreach ($pids as $key => $pid) {
        if (!preg_match('/^\d+$/', $pid)) {
          $console->writeErr(pht("PID '%s' is not a valid PID.", $pid)."\n");
          continue;
        } else if (empty($running_pids[$pid])) {
          $console->writeErr(
            pht(
              'PID "%d" is not a known Phabricator daemon PID. It will not '.
              'be killed.',
              $pid)."\n");
          continue;
        } else {
          $stop_pids[$pid] = $pid;
        }
      }
    }

    if (!$stop_pids) {
      $console->writeErr(pht('No daemons to kill.')."\n");
      return 0;
    }

    $survivors = $this->sendStopSignals($stop_pids, $grace_period);

    // Try to clean up PID files for daemons we killed.
    $remove = array();
    foreach ($daemons as $daemon) {
      $pid = $daemon->getPID();
      if (empty($stop_pids[$pid])) {
        // We did not try to stop this overseer.
        continue;
      }

      if (isset($survivors[$pid])) {
        // We weren't able to stop this overseer.
        continue;
      }

      if (!$daemon->getPIDFile()) {
        // We don't know where the PID file is.
        continue;
      }

      $remove[] = $daemon->getPIDFile();
    }

    foreach (array_unique($remove) as $remove_file) {
      Filesystem::remove($remove_file);
    }

    if (!$gently) {
      $this->processRogueDaemons($grace_period, !$pids, $force);
    }

    return 0;
  }

  private function processRogueDaemons($grace_period, $warn, $force_stop) {
    $console = PhutilConsole::getConsole();

    $rogue_daemons = PhutilDaemonOverseer::findRunningDaemons();
    if ($rogue_daemons) {
      if ($force_stop) {
        $rogue_pids = ipull($rogue_daemons, 'pid');
        $survivors = $this->sendStopSignals($rogue_pids, $grace_period);
        if ($survivors) {
          $console->writeErr(
            pht(
              'Unable to stop processes running without PID files. '.
              'Try running this command again with sudo.')."\n");
        }
      } else if ($warn) {
        $console->writeErr($this->getForceStopHint($rogue_daemons)."\n");
      }
    }

    return $rogue_daemons;
  }

  private function getForceStopHint($rogue_daemons) {
    $debug_output = '';
    foreach ($rogue_daemons as $rogue) {
      $debug_output .= $rogue['pid'].' '.$rogue['command']."\n";
    }
    return pht(
      'There are processes running that look like Phabricator daemons but '.
      'have no corresponding PID files:'."\n\n".'%s'."\n\n".
      'Stop these processes by re-running this command with the --force '.
      'parameter.',
      $debug_output);
  }

  private function sendStopSignals($pids, $grace_period) {
    // If we're doing a graceful shutdown, try SIGINT first.
    if ($grace_period) {
      $pids = $this->sendSignal($pids, SIGINT, $grace_period);
    }

    // If we still have daemons, SIGTERM them.
    if ($pids) {
      $pids = $this->sendSignal($pids, SIGTERM, 15);
    }

    // If the overseer is still alive, SIGKILL it.
    if ($pids) {
      $pids = $this->sendSignal($pids, SIGKILL, 0);
    }

    return $pids;
  }

  private function sendSignal(array $pids, $signo, $wait) {
    $console = PhutilConsole::getConsole();

    $pids = array_fuse($pids);

    foreach ($pids as $key => $pid) {
      if (!$pid) {
        // NOTE: We must have a PID to signal a daemon, since sending a signal
        // to PID 0 kills this process.
        unset($pids[$key]);
        continue;
      }

      switch ($signo) {
        case SIGINT:
          $message = pht('Interrupting process %d...', $pid);
          break;
        case SIGTERM:
          $message = pht('Terminating process %d...', $pid);
          break;
        case SIGKILL:
          $message = pht('Killing process %d...', $pid);
          break;
      }

      $console->writeOut("%s\n", $message);
      posix_kill($pid, $signo);
    }

    if ($wait) {
      $start = PhabricatorTime::getNow();
      do {
        foreach ($pids as $key => $pid) {
          if (!PhabricatorDaemonReference::isProcessRunning($pid)) {
            $console->writeOut(pht('Process %d exited.', $pid)."\n");
            unset($pids[$key]);
          }
        }
        if (empty($pids)) {
          break;
        }
        usleep(100000);
      } while (PhabricatorTime::getNow() < $start + $wait);
    }

    return $pids;
  }

  private function freeActiveLeases() {
    $task_table = id(new PhabricatorWorkerActiveTask());
    $conn_w = $task_table->establishConnection('w');
    queryfx(
      $conn_w,
      'UPDATE %T SET leaseExpires = UNIX_TIMESTAMP()
        WHERE leaseExpires > UNIX_TIMESTAMP()',
      $task_table->getTableName());
    return $conn_w->getAffectedRows();
  }

}
