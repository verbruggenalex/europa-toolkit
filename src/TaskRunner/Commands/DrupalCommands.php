<?php

namespace VerbruggenAlex\DrupalToolkit\TaskRunner\Commands;

use OpenEuropa\TaskRunner\Commands\AbstractCommands;
use NuvoleWeb\Robo\Task as NuvoleWebTasks;
use OpenEuropa\TaskRunner\Contract\FilesystemAwareInterface;
use OpenEuropa\TaskRunner\Tasks as TaskRunnerTasks;
use OpenEuropa\TaskRunner\Traits as TaskRunnerTraits;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class DrupalCommands.
 */
class DrupalCommands extends AbstractCommands implements FilesystemAwareInterface
{
    use TaskRunnerTraits\ConfigurationTokensTrait;
    use TaskRunnerTraits\FilesystemAwareTrait;
    use TaskRunnerTasks\CollectionFactory\loadTasks;
    use NuvoleWebTasks\Config\loadTasks;

    /**
     * Create required files.
     *
     * @command drupal:create-required-files
     */
    public function drupalCreateRequiredFiles()
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $taskCollection = [];
        $dirs = [
          'modules',
          'profiles',
          'themes',
        ];
    
        // Required for unit testing
        foreach ($dirs as $dir) {
          if (!file_exists($drupalRoot . '/'. $dir)) {
            $taskCollection[] = $this->taskFilesystemStack()
              ->mkdir($drupalRoot . '/'. $dir)
              ->touch($drupalRoot . '/'. $dir . '/.gitkeep');
          }
        }
    
        // Prepare the settings file for installation
        if (!file_exists($drupalRoot . '/sites/default/settings.php') && file_exists($drupalRoot . '/sites/default/default.settings.php')) {
          $taskCollection[] = $this->taskFilesystemStack()
            ->copy($drupalRoot . '/sites/default/default.settings.php', $drupalRoot . '/sites/default/settings.php');
          require_once $drupalRoot . '/core/includes/bootstrap.inc';
          require_once $drupalRoot . '/core/includes/install.inc';
          $settings['config_directories'] = [
            CONFIG_SYNC_DIRECTORY => (object) [
              'value' => '../config/sync',
              'required' => TRUE,
            ],
          ];
          drupal_rewrite_settings($settings, $drupalRoot . '/sites/default/settings.php');
          $taskCollection[] = $this->taskFilesystemStack()
            ->chmod($drupalRoot . '/sites/default/settings.php', 0666);
        }
    
        // Create the files directory with chmod 0777
        if (!file_exists($drupalRoot . '/sites/default/files')) {
          $oldmask = umask(0);
          $taskCollection[] = $this->taskFilesystemStack()
            ->chmod($drupalRoot . '/sites/default/files', 0777);
          umask($oldmask);
        }

        return $this->collectionBuilder()->addTaskList($taskCollection);
    }

    /**
     * Enable all Drupal modules.
     *
     * @command drupal:enable-all
     *
     * @option disable  Comma seperated list of modules to disable.
     *
     * @param array $options
     */
    public function drupalEnableAll(array $options = [
      'exclude' => InputOption::VALUE_OPTIONAL,
    ])
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalProfile = $this->getConfig()->get('drupal.site.profile');
        $enableallExclude = !empty($options['exclude']) ? explode(',', $options['exclude']) : $this->getConfig()->get('drupal.site.enable_all_exclude');
        $systemList = array_keys(json_decode($this->taskExec('./vendor/bin/drush pm:list --type=module --format=json')->printOutput(false)->run()->getMessage(), true));
        // Remove example modules from list.
        foreach ($systemList as $key => $value) {
            if (false !== strpos($value, '_example') || in_array($value, array('webprofiler'))) {
                unset($systemList[$key]);
            }
        }
        $enabled = array_keys(json_decode($this->taskExec('./vendor/bin/drush pm:list --type=module --format=json --status=enabled')->printOutput(false)->run()->getMessage(), true));
        $disabled = array_diff($systemList, $enabled);

        $enableModules = array_diff($disabled, $enableallExclude);
        $disableModules = $enableallExclude;
        $taskCollection = array();

        $taskCollection[] = $this->taskExec('vendor/bin/drush pm:enable '.implode(',', $enableModules).' -y');

        return $this->collectionBuilder()->addTaskList($taskCollection);
    }

    /**
     * Switch on or off modules and users.
     *
     * @command drupal:switch-mode
     * 
     * @option modules  Comma separated list of modules to enable or disable.
     * @option users    Comma separated list of users to block or unblock.
     * @option mode     The mode you wish to run (on/off).
     *
     * @param array $options
     */
    public function drupalSwitchMode(array $options = [
      'modules' => InputOption::VALUE_OPTIONAL,
      'users' => InputOption::VALUE_OPTIONAL,
      'mode' => InputOption::VALUE_REQUIRED,
    ])
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalVersion = $this->taskExec('./vendor/bin/drush status --field=drupal-version')->printOutput(false)->run()->getMessage();
        $drupalSite = $this->getConfig()->get('drupal.site.sites_subdir');
        $sitePath = $drupalRoot.'/sites/'.$drupalSite;
        $taskCollection = [];

        if (!empty($options['users'])) {
            $users = $options['users'];
            $action = $options['mode'] == 'on' ? 'user:unblock' : 'user:block'; 
            $taskCollection[] = $this->taskExec("vendor/bin/drush {$action} {$users}");
        }
        if (!empty($options['modules'])) {
            $modules = $options['modules'];
            $action = $options['mode'] == 'on' ? 'pm:enable' : 'pm:uninstall'; 
            $taskCollection[] = $this->taskExec("vendor/bin/drush {$action} {$modules}");
        }
        if (!empty($taskCollection)) {
            return $this->collectionBuilder()->addTaskList($taskCollection);
        }
        else {
            $this->say("No modules or users to perform switch on.");
        }
    }

    /**
     * Generate Drupal data with devel.
     *
     * @TODO: Follow up batch issue.
     * @link: https://www.drupal.org/project/devel/issues/2866990
     *
     * @command drupal:generate-data
     */
    public function drupalGenerateData()
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalVersion = $this->getConfig()->get('drupal.version');
        if ($this->taskExec('vendor/bin/drush en devel_generate -y')->run()) {
            // Generate a vocabulary if needed.
            if ($this->taskExec('vendor/bin/drush devel-generate-vocabs 1')->run()) {
                $vocabularyName = $this->taskExec('./vendor/bin/drush eval "echo array_keys(\Drupal\taxonomy\Entity\Vocabulary::loadMultiple())[0];"')->printOutput(false)->run()->getMessage();
            }
            $contentTypes = $this->taskExec('./vendor/bin/drush eval "echo implode(\',\', array_keys(\Drupal\node\Entity\NodeType::loadMultiple()));"')->printOutput(false)->run()->getMessage();
            // Generate other data.
            $taskCollection = array(
                $this->taskExec('vendor/bin/drush devel-generate-users 50 --kill --pass=password'),
                $this->taskExec("vendor/bin/drush devel-generate-terms $vocabularyName 50 --kill"),
                $this->taskExec("vendor/bin/drush devel-generate-content 50 3 --kill --types=$contentTypes --kill"),
                $this->taskExec('vendor/bin/drush devel-generate-menus 2 50 --kill'),
            );

            return $this->collectionBuilder()->addTaskList($taskCollection);
        }
    }

    /**
     * Run behat tests.
     *
     * @command drupal:core-behat
     */
    public function drupalCoreBehat()
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalProfile = $this->getConfig()->get('drupal.site.profile');
        $drupalVersion = 8; //$this->getConfig()->get('drupal.version');
        $drupalSite = $this->getConfig()->get('drupal.site.sites_subdir');
        $sitePath = $drupalRoot.'/sites/'.$drupalSite;

        if ('standard' == $drupalProfile) {
            return $this->taskExec('./vendor/bin/behat -c tests/behat/behat.yml')->run();
            // $this->taskFilesystemStack()->stopOnFail()
            //     ->mkdir('web/sites/all/modules/contrib')
            //     ->symlink(getcwd().'/tests/behat/fixtures/drupal'.$drupalVersion.'/modules/behat_test', getcwd().'/web/modules/contrib/behat_test')
            //     ->run();
            // if ($this->taskExec('vendor/bin/drush pm:enable locale behat_test -y')->run()) {
            //     return $this->taskExec('./vendor/bin/behat -c tests/behat/behat.yml')->run();
            // }
        } else {
            $this->say("Skipping Drupal Core Behat tests. Only available on 'standard' profile.");
        }
    }

    /**
     * Backup configuration and database.
     *
     * @command drupal:backup-project
     */
    public function drupalBackupProject()
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalVersion = $this->taskExec('./vendor/bin/drush status --field=drupal-version')->printOutput(false)->run()->getMessage();
        $drupalSite = $this->getConfig()->get('drupal.site.sites_subdir');
        $sitePath = $drupalRoot.'/sites/'.$drupalSite;
        $taskCollection = [];
        var_dump($drupalVersion);

        if (8 == $drupalVersion[0] && $this->taskExec('vendor/bin/drush config-export -y')->run()) {
            $taskCollection[] = $this->taskExec('vendor/bin/drush sql:dump --result-file=../docker/data/mysql/dump.sql');
            $taskCollection[] = $this->taskExec('vendor/bin/drush gdpr:sql:dump --result-file=../docker/data/mysql/dump-sanitized.sql');

            return $this->collectionBuilder()->addTaskList($taskCollection);
        }
    }

    /**
     * Generate one user per role for development purposes.
     *
     * @command drupal:generate-users
     *
     * @TODO: Make sure that the username_validation module does not allow these
     * usernames to be registered on the site!
     */
    public function drupalGenerateUsers()
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalVersion = $this->taskExec('./vendor/bin/drush status --field=drupal-version')->printOutput(false)->run()->getMessage();
        $drupalSite = $this->getConfig()->get('drupal.site.sites_subdir');
        $sitePath = $drupalRoot.'/sites/'.$drupalSite;

        if (8 == $drupalVersion[0] && $roles = json_decode($this->taskExec('./vendor/bin/drush role:list --format=json')->printOutput(false)->run()->getMessage())) {
            $taskCollection = [];
            foreach ($roles as $key => $role) {
                if ('anonymous' != $key) {
                    if (empty($this->taskExec("vendor/bin/drush sqlq 'select name from users_field_data where name=\"{$key}\"'")->printOutput(false)->run()->getMessage())) {
                        $taskCollection[] = $this->taskExec("vendor/bin/drush user:create {$key} --mail={$key}@example.com --password={$key}");
                        if ('authenticated' != $key) {
                            $taskCollection[] = $this->taskExec("vendor/bin/drush user:role:add {$key} {$key}");
                        }
                    } else {
                        $this->say("User {$key} already exists.");
                    }
                }
            }

            return $this->collectionBuilder()->addTaskList($taskCollection);
        }
    }

    /**
     * Generate a cookie for backstopjs.
     *
     * @command drupal:generate-cookie
     *
     * @TODO: Maybe use drush uli so you don't need password, just username.
     *
     * @option user     The user you want to be logged in as.
     * @option pass     The password for the user account.
     *
     * @param array $options
     */
    public function drupalgenerateCookie(array $options = [
      'user' => InputOption::VALUE_OPTIONAL,
      'pass' => InputOption::VALUE_OPTIONAL,
    ])
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalVersion = 8; //$this->getConfig()->get('drupal.version');
        $drupalSite = $this->getConfig()->get('drupal.site.sites_subdir');
        $sitePath = $drupalRoot.'/sites/'.$drupalSite;
        $cookieDomain = 'web';
        $user = isset($options['user']) ? $options['user'] : 'admin';
        $pass = isset($options['pass']) ? $options['pass'] : 'admin';
        $cookiePath = 'tests/backstop/backstop_data/engine_scripts/cookie.txt';
        $loginCommand = 'curl --cookie-jar '.$cookiePath.'  -vk --header "Content-type: application/json" --request POST --data \'{"name":"'.$user.'", "pass":"'.$pass.'"}\' http://'.$cookieDomain.'/user/login?_format=json';
        $loginCommand = 'curl --cookie-jar '.$cookiePath.'  -vkL "$(./vendor/bin/drush @local uli --name='.$user.')"';
        $backstop_cookies = array();

        if (8 == $drupalVersion && $this->taskExec($loginCommand)->run()) {
            $this->taskExec("sed -i '1,/^$/d' {$cookiePath}")->run();
            $cookies = file($cookiePath);
            foreach ($cookies as $cookie) {
                $cookie_parts = preg_split('/\s+/', trim($cookie));
                $backstop_cookies[] = array(
                    'domain' => $cookieDomain,
                    'path' => '/',
                    'name' => $cookie_parts[5],
                    'value' => $cookie_parts[6],
                    'expirationDate' => $cookie_parts[4],
                    'hostOnly' => false,
                    'httpOnly' => false,
                    'secure' => false,
                    'session' => false,
                    'sameSite' => 'no_restriction',
                );
            }
            file_put_contents('tests/backstop/backstop_data/engine_scripts/cookies.json', json_encode($backstop_cookies, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }

    /**
     * Run smoke tests.
     *
     * @command drupal:drush-smoke
     */
    public function drupalDrushSmoke()
    {
        $drupalRoot = $this->getConfig()->get('drupal.root');
        $drupalVersion = $this->getConfig()->get('drupal.version');
        $drupalSite = $this->getConfig()->get('drupal.site.sites_subdir');
        $sitePath = $drupalRoot.'/sites/'.$drupalSite;

        if (7 == $drupalVersion && $this->taskExec('vendor/bin/drush en dblog -y')->run()) {
            return $this->taskExec('vendor/bin/drush watchdog-smoketest');
        } else {
            return $this->say('Skipping Drupal Drush Smoke tests. Only available for Drupal 7 at the moment.');
        }
    }

    /**
     * Run Grumphp coding standards.
     *
     * @command drupal:grumphp
     */
    public function drupalGrumphp()
    {
        // Run grumphp.
        return $this->taskExec('./vendor/bin/grumphp run')->run();
    }
}
