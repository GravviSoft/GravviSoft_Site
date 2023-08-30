<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * BounceHandlerCommand
 *
 * @package MailWizz EMA
 * @author MailWizz Development Team <support@mailwizz.com>
 * @link https://www.mailwizz.com/
 * @copyright MailWizz EMA (https://www.mailwizz.com)
 * @license https://www.mailwizz.com/license/
 * @since 1.0
 */

class BounceHandlerCommand extends ConsoleCommand
{
    /**
     * @var int
     */
    public $fast = 1;

    /**
     * @return void
     * @throws CException
     */
    public function init()
    {
        parent::init();

        Yii::import('common.vendors.BounceHandler.*');
    }

    /**
     * @return int
     */
    public function actionIndex()
    {
        $this->stdout('Starting...');

        // because some cli are not compiled same way with the web module.
        if (!CommonHelper::functionExists('imap_open')) {
            Yii::log(t('servers', 'The PHP CLI binary is missing the IMAP extension!'), CLogger::LEVEL_ERROR);
            $this->stdout('The PHP CLI binary is missing the IMAP extension!');
            return 1;
        }

        // make sure we only allow a single cron job at a time if this flag is disabled
        $fastLockName = sha1(__METHOD__);
        if (!$this->fast && !mutex()->acquire($fastLockName, 5)) {
            $this->stdout('Cannot acquire lock, seems another process is already running!');
            return 0;
        }

        try {

            // since 1.5.0
            BounceServer::model()->updateAll([
                'status' => BounceServer::STATUS_ACTIVE,
            ], 'status = :st', [
                ':st' => BounceServer::STATUS_CRON_RUNNING,
            ]);
            //

            // added in 1.3.4.7
            hooks()->doAction('console_command_bounce_handler_before_process', $this);

            $this->stdout('Starting processing...');

            if ($this->getCanUsePcntl()) {
                $this->processWithPcntl();
            } else {
                $this->processWithoutPcntl();
            }

            $this->stdout('Processing finished.');

            // added in 1.3.4.7
            hooks()->doAction('console_command_bounce_handler_after_process', $this);
        } catch (Exception $e) {
            $this->stdout(__LINE__ . ': ' . $e->getMessage());
            Yii::log($e->getMessage(), CLogger::LEVEL_ERROR);
        }

        if (!$this->fast) {
            mutex()->release($fastLockName);
        }

        return 0;
    }

    /**
     * @return $this
     * @throws CException
     */
    protected function processWithPcntl()
    {
        // get all servers
        $servers = BounceServer::model()->findAll([
            'condition' => 't.status = :status',
            'params'    => [':status' => BounceServer::STATUS_ACTIVE],
        ]);

        if (is_countable($servers)) {
            $this->stdout(sprintf('Found %d servers for processing...', count($servers)));
        }

        // close the external connections
        $this->setExternalConnectionsActive(false);

        /** @var OptionCronProcessBounceServers $optionCronProcessBounceServers */
        $optionCronProcessBounceServers = container()->get(OptionCronProcessBounceServers::class);

        // split into x server chunks
        $chunkSize = (int)$optionCronProcessBounceServers->getPcntlProcesses();
        $serverChunks = array_chunk($servers, $chunkSize);
        unset($servers);

        foreach ($serverChunks as $servers) {
            $children = [];

            foreach ($servers as $server) {
                $pid = pcntl_fork();
                if ($pid == -1) {
                    continue;
                }

                // Parent
                if ($pid) {
                    $children[] = $pid;
                }

                // child
                if (!$pid) {
                    try {
                        $this->stdout(sprintf('Started processing server ID %d.', $server->server_id));

                        $server->processRemoteContents([
                            'logger' => $this->verbose ? [$this, 'stdout'] : null,
                        ]);

                        $this->stdout(sprintf('Finished processing server ID %d.', $server->server_id));
                    } catch (Exception $e) {
                        $this->stdout(__LINE__ . ': ' . $e->getMessage());
                        Yii::log($e->getMessage(), CLogger::LEVEL_ERROR);
                    }
                    app()->end();
                }
            }

            while (count($children) > 0) {
                foreach ($children as $key => $pid) {
                    $res = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($res == -1 || $res > 0) {
                        unset($children[$key]);
                    }
                }
                sleep(1);
            }
        }

        return $this;
    }

    /**
     * @return $this
     * @throws Exception
     */
    protected function processWithoutPcntl()
    {
        // get all servers
        $servers = BounceServer::model()->findAll([
            'condition' => 't.status = :status',
            'params'    => [':status' => BounceServer::STATUS_ACTIVE],
        ]);

        if (is_array($servers)) {
            $this->stdout(sprintf('Found %d servers for processing...', count($servers)));
        }

        foreach ($servers as $server) {
            try {
                $this->stdout(sprintf('Started processing server ID %d.', $server->server_id));

                $server->processRemoteContents([
                    'logger' => $this->verbose ? [$this, 'stdout'] : null,
                ]);

                $this->stdout(sprintf('Finished processing server ID %d.', $server->server_id));
            } catch (Exception $e) {
                $this->stdout(__LINE__ . ': ' . $e->getMessage());
                Yii::log($e->getMessage(), CLogger::LEVEL_ERROR);
            }
        }

        return $this;
    }

    /**
     * @return bool
     */
    protected function getCanUsePcntl()
    {
        static $canUsePcntl;
        if ($canUsePcntl !== null) {
            return $canUsePcntl;
        }

        /** @var OptionCronProcessBounceServers $optionCronProcessBounceServers */
        $optionCronProcessBounceServers = container()->get(OptionCronProcessBounceServers::class);

        if (!$optionCronProcessBounceServers->getUsePcntl()) {
            return $canUsePcntl = false;
        }
        if (!CommonHelper::functionExists('pcntl_fork') || !CommonHelper::functionExists('pcntl_waitpid')) {
            return $canUsePcntl = false;
        }
        return $canUsePcntl = true;
    }
}
