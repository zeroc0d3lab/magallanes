<?php
/**
 * This file is part of the Magallanes package.
 *
 * (c) Hector Nguyen <hectornguyen@octopius.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 **/

namespace Mage\Task;

use Exception;
use Mage\Config;
use Mage\Console;
use Mage\Task\Releases\IsReleaseAware;

/**
 * Abstract Class for a Magallanes Task
 *
 * @author Hector Nguyen <hectornguyen@octopius.com>
 */
abstract class AbstractTask
{
    /**
     * Stage Constant for Pre Deployment
     * @var string
     */
    const STAGE_PRE_DEPLOY = 'pre-deploy';

    /**
     * Stage Constant for Deployment
     * @var string
     */
    const STAGE_DEPLOY = 'deploy';

    /**
     * Stage Constant for Post Deployment
     * @var string
     */
    const STAGE_POST_DEPLOY = 'post-deploy';

    /**
     * Stage Constant for Post Release
     * @var string
     */
    const STAGE_POST_RELEASE = 'post-release';

    /**
     * Configuration
     * @var Config;
     */
    protected $config = null;

    /**
     * Indicates if the Task is running in a Rollback
     * @var boolean
     */
    protected $inRollback = false;

    /**
     * Indicates the Stage the Task is running ing
     * @var string
     */
    protected $stage = null;

    /**
     * Extra parameters
     * @var array
     */
    protected $parameters = array();

    /**
     * Returns the Title of the Task
     * @return string
     */
    abstract public function getName();

    /**
     * Runs the task
     *
     * @return boolean
     * @throws Exception
     * @throws ErrorWithMessageException
     * @throws SkipException
     */
    abstract public function run();

    /**
     * Task Constructor
     *
     * @param Config $config
     * @param boolean $inRollback
     * @param string $stage
     * @param array $parameters
     */
    final public function __construct(Config $config, $inRollback = false, $stage = null, $parameters = array())
    {
        $this->config     = $config;
        $this->inRollback = $inRollback;
        $this->stage      = $stage;
        $this->parameters = $parameters;
    }

    /**
     * Indicates if the Task is running in a Rollback operation
     * @return boolean
     */
    public function inRollback()
    {
        return $this->inRollback;
    }

    /**
     * Gets the Stage of the Deployment:
     *     - pre-deploy
     *     - deploy
     *     - post-deploy
     *     - post-release
     * @return string
     */
    public function getStage()
    {
        return $this->stage;
    }

    /**
     * Gets the Configuration
     * @return Config;
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Initializes the Task, optional to implement
     */
    public function init()
    {
    }

    /**
     * Returns a Parameter, or a default if not found
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getParameter($name, $default = null)
    {
        return $this->getConfig()->getParameter($name, $default, $this->getParameters());
    }

    /**
     * @return array
     */
    protected function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Runs a Shell Command Localy
     *
     * @param string $command
     * @param string $output
     * @return boolean
     */
    final protected function runCommandLocal($command, &$output = null)
    {
        return Console::executeCommand($command, $output);
    }

    /**
     * Runs a Shell Command on the Remote Host
     *
     * @param string $command
     * @param string $output
     * @param boolean $cdToDirectoryFirst
     * @return boolean
     */
    final protected function runCommandRemote($command, &$output = null, $cdToDirectoryFirst = true)
    {
        if ($this->getConfig()->release('enabled', false) === true) {
            if ($this instanceof IsReleaseAware) {
                $releasesDirectory = '';
            } else {
                $releasesDirectory = '/'
                . $this->getConfig()->release('directory', 'releases')
                . '/'
                . $this->getConfig()->getReleaseId();
            }
        } else {
            $releasesDirectory = '';
        }

        // if general.yml includes "ssy_needs_tty: true", then add "-t" to the ssh command
        $needs_tty = ($this->getConfig()->general('ssh_needs_tty', false) ? '-t' : '');

        $localCommand = 'ssh ' . $this->getConfig()->getHostIdentityFileOption() . $needs_tty . ' -p ' . $this->getConfig()->getHostPort() . ' '
        . '-q -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no '
        . $this->getConfig()->getConnectTimeoutOption()
        . ($this->getConfig()->deployment('user') != '' ? $this->getConfig()->deployment('user') . '@' : '')
        . $this->getConfig()->getHostName();

        $remoteCommand = str_replace('"', '\"', $command);
        if ($cdToDirectoryFirst) {
            $remoteCommand = 'cd ' . rtrim($this->getConfig()->deployment('to'), '/') . $releasesDirectory . ' && ' . $remoteCommand;
        }
        $localCommand .= ' ' . '"sh -c \"' . $remoteCommand . '\""';

        Console::log('Run remote command ' . $remoteCommand);

        return $this->runCommandLocal($localCommand, $output);
    }

    /**
     * Runs a Shell Command Localy or in the Remote Host based on the Task Stage.
     * If the stage is "deploy" then it will be executed in the remote host.
     *
     * @param string $command
     * @param string $output
     * @return boolean
     */
    final protected function runCommand($command, &$output = null)
    {
        if ($this->getStage() == self::STAGE_DEPLOY || $this->getStage() == self::STAGE_POST_RELEASE) {
            return $this->runCommandRemote($command, $output);
        } else {
            return $this->runCommandLocal($command, $output);
        }
    }

    /**
     * Adds a cd to the needed release if we work with releases.
     *
     * @param string $command
     * @return string
     */
    protected function getReleasesAwareCommand($command)
    {
        if ($this->getConfig()->release('enabled', false) === true) {
            $releasesDirectory = $this->getConfig()->release('directory', 'releases');

            $deployToDirectory = $releasesDirectory . '/' . $this->getConfig()->getReleaseId();
            return 'cd ' . $deployToDirectory . ' && ' . $command;
        }

        return $command;
    }

    /**
     * adds a cd to the needed git remote cache directory if we work with git-remote-cache.
     *
     * @param string $command
     * @return string
     */
    protected function getGitCacheAwareCommand($command)
    {
        if ($this->getConfig()->extras('enabled', 'top', false) === true) {
            if ($this->getConfig()->extras('vcs', 'enabled', false) === true) {
                $sharedDirectory = $this->getConfig()->extras('directory', 'top', 'shared');
                $cacheDirectory  = $this->getConfig()->extras('vcs', 'directory', 'git-remote-cache');

                $remoteCacheDirectory = $sharedDirectory . '/' . $cacheDirectory;
                return 'cd ' . $remoteCacheDirectory . ' && ' . $command;
            }
        }

        return $command;
    }

    /**
     * adds a cd to the needed rsync remote cache directory if we work with rsync-remote-cache.
     *
     * @param string $command
     * @return string
     */
    protected function getRsyncCacheAwareCommand($command)
    {
        if ($this->getConfig()->extras('enabled', 'top', false) === true) {
            if ($this->getConfig()->extras('rsync', 'enabled', false) === true) {
                $sharedDirectory = $this->getConfig()->extras('directory', 'top', 'shared');
                $cacheDirectory  = $this->getConfig()->extras('rsync', 'directory', 'rsync-remote-cache');

                $remoteCacheDirectory = $sharedDirectory . '/' . $cacheDirectory;
                return 'cd ' . $remoteCacheDirectory . ' && ' . $command;
            }
        }

        return $command;
    }

    /**
     * @param integer $releaseId
     * @return bool
     */
    protected function tarRelease($releaseId)
    {
        $result = true;
        // for given release, check if tarred
        $output            = '';
        $releasesDirectory = $this->getConfig()->release('directory', 'releases');

        $currentReleaseDirectory     = $releasesDirectory . '/' . $releaseId;
        $currentReleaseDirectoryTemp = $currentReleaseDirectory . '_tmp/';
        $currentRelease              = $currentReleaseDirectory . '/' . $releaseId . '.tar.gz';

        $command = 'test -e ' . $currentRelease . ' && echo "true" || echo ""';
        $this->runCommandRemote($command, $output);

        // if not, do so
        if (!$output) {
            $commands   = array();
            $commands[] = 'mv ' . $currentReleaseDirectory . ' ' . $currentReleaseDirectoryTemp;
            $commands[] = 'mkdir ' . $currentReleaseDirectory;
            $commands[] = 'tar cfz ' . $currentRelease . ' ' . $currentReleaseDirectoryTemp;
            $commands[] = 'rm -rf ' . $currentReleaseDirectoryTemp;
            $command    = implode(' && ', $commands);
            $result     = $this->runCommandRemote($command, $output);
            return $result;
        }
        return $result;
    }

    protected function untarRelease($releaseId)
    {
        $result = true;
        // for given release, check if tarred
        $output            = '';
        $releasesDirectory = $this->getConfig()->release('directory', 'releases');

        $currentReleaseDirectory     = $releasesDirectory . '/' . $releaseId;
        $currentReleaseDirectoryTemp = $currentReleaseDirectory . '_tmp/';
        $currentRelease              = $currentReleaseDirectory . '/' . $releaseId . '.tar.gz';

        $command = 'test -e ' . $currentRelease . ' && echo "true" || echo ""';
        $this->runCommandRemote($command, $output);

        // if tarred, untar now
        if ($output) {
            $commands   = array();
            $commands[] = 'tar xfz ' . $currentRelease;
            $commands[] = 'rm -rf ' . $currentReleaseDirectory;
            $commands[] = 'mv ' . $currentReleaseDirectoryTemp . ' ' . $currentReleaseDirectory;
            $command    = implode(' && ', $commands);
            $result     = $this->runCommandRemote($command, $output);
            return $result;
        }
        return $result;
    }
}
