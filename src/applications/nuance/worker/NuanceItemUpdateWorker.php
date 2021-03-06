<?php

final class NuanceItemUpdateWorker
  extends NuanceWorker {

  protected function doWork() {
    $item_phid = $this->getTaskDataValue('itemPHID');

    $hash = PhabricatorHash::digestForIndex($item_phid);
    $lock_key = "nuance.item.{$hash}";
    $lock = PhabricatorGlobalLock::newLock($lock_key);

    $lock->lock(1);
    try {
      $item = $this->loadItem($item_phid);
      $this->updateItem($item);
      $this->routeItem($item);
      $this->applyCommands($item);
    } catch (Exception $ex) {
      $lock->unlock();
      throw $ex;
    }

    $lock->unlock();
  }

  private function updateItem(NuanceItem $item) {
    $impl = $item->getImplementation();
    if (!$impl->canUpdateItems()) {
      return null;
    }

    $viewer = $this->getViewer();

    $impl->setViewer($viewer);
    $impl->updateItem($item);
  }

  private function routeItem(NuanceItem $item) {
    $status = $item->getStatus();
    if ($status != NuanceItem::STATUS_ROUTING) {
      return;
    }

    $source = $item->getSource();

    // For now, always route items into the source's default queue.

    $item
      ->setQueuePHID($source->getDefaultQueuePHID())
      ->setStatus(NuanceItem::STATUS_OPEN)
      ->save();
  }

  private function applyCommands(NuanceItem $item) {
    $viewer = $this->getViewer();

    $impl = $item->getImplementation();
    $impl->setViewer($viewer);

    $commands = id(new NuanceItemCommandQuery())
      ->setViewer($viewer)
      ->withItemPHIDs(array($item->getPHID()))
      ->withStatuses(
        array(
          NuanceItemCommand::STATUS_ISSUED,
        ))
      ->execute();
    $commands = msort($commands, 'getID');

    $executors = NuanceCommandImplementation::getAllCommands();
    foreach ($commands as $command) {
      $command
        ->setStatus(NuanceItemCommand::STATUS_EXECUTING)
        ->save();

      try {
        $command_key = $command->getCommand();

        $executor = idx($executors, $command_key);
        if (!$executor) {
          throw new Exception(
            pht(
              'Unable to execute command "%s": this command does not have '.
              'a recognized command implementation.',
              $command_key));
        }

        $executor = clone $executor;

        $executor
          ->setActor($viewer)
          ->applyCommand($item, $command);

        $command
          ->setStatus(NuanceItemCommand::STATUS_DONE)
          ->save();
      } catch (Exception $ex) {
        $command
          ->setStatus(NuanceItemCommand::STATUS_FAILED)
          ->save();

        throw $ex;
      }
    }
  }

}
