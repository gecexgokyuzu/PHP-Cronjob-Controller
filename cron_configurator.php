<?php

/**
 * Class CronController
 * 
 * Manages the execution of cron jobs based on a specified configuration. It handles the initialization
 * of cron jobs, task management, logging actions, and iterating through tasks. The class ensures that
 * cron jobs run at specified intervals, can dynamically add tasks, and log their actions for monitoring
 * and debugging purposes.
 * 
 * **PLEASE CHECK THE BOTTOM OF THIS FILE FOR A FULL USAGE EXAMPLE.**
 *
 * @package CronController
 */
class CronController
{
    /**
     * @var string Path to use for saving or retrieving configuration file.
     */
    protected $config_file_path;

    /**
     * @var string Path to use for saving or retrieving the log file.
     */
    protected $logs_file_path;

    /**
     * @var array Configuration for the cron job.
     */
    protected $cron_config;

    /**
     * @var string Date for the current cron execution -> date('Y-m-d').
     */
    protected $today;

    /**
     * @var string Interval at which the cron job should run.
     */
    protected $run_interval;

    /**
     * @var array Flags for custom cron behavior.
     */
    protected $flags = array();

    /**
     * @var array Mailer settings to use at the end of the cronjob.
     */
    protected $cron_mailer = array();

    /**
     * Constructor is private to prevent direct instantiation. Use the ::create method instead.
     * Initializes the cron job with the provided configuration.
     *
     * @param array $cron_configuration Configuration parameters for the cron job.
     */
    private function __construct($cron_configuration)
    {
        $this->today = $cron_configuration['today'];
        $this->config_file_path = $cron_configuration['config_directory'] . '/cron_config.json';
        $this->logs_file_path = $cron_configuration['logs_directory'] . '/' . $this->today . '.txt';
        $this->run_interval = $cron_configuration['run_interval'];
        $this->flags = $cron_configuration['cron_flags'] ?? array();
        $this->cron_mailer = $cron_configuration['cron_mailer'] ?? array();

        $this->loadConfig();
        $this->canItRun();
    }

    /**
     * Initializes the cron job with the specified configuration. Validates the configuration,
     * creates necessary files and directories, and returns an instance of the CronController.
     *
     * @param array $cron_configuration Configuration parameters for the cron job.
     * @return CronController An instance of the CronController.
     * @throws Exception If the configuration is invalid or necessary files/directories cannot be created.
     */
    public static function create($cron_configuration)
    {
        //---set default day if it exists, or just use today
        if (isset($cron_configuration['today'])) {
            $date = DateTime::createFromFormat('Y-m-d', $cron_configuration['today']);
            if (!$date || !$date->format('Y-m-d') === $cron_configuration['today']) {
                throw new Exception('Invalid date format for "today". Expected format is "Y-m-d".');
            }
        } else {
            $cron_configuration['today'] = date('Y-m-d');
        }

        //---check if .txt file can be created in the specified directory
        if (!isset($cron_configuration['logs_directory'])) {
            throw new Exception('logs_directory is not set in the configurator array.');
        }

        if (!file_exists($cron_configuration['logs_directory'])) {
            if (!mkdir($cron_configuration['logs_directory'], 0777, true)) {
                throw new Exception('Failed to create logs directory.');
            }
        }

        if (!file_exists($cron_configuration['logs_directory'] . '/' . date('Y-m-d') . '.txt')) {
            if (!touch($cron_configuration['logs_directory'] . '/' . date('Y-m-d') . '.txt')) {
                throw new Exception('Failed to create logs.txt file.');
            }
        }

        //---check if cron_config.json file can be created in the specified directory
        if (!isset($cron_configuration['config_directory'])) {
            throw new Exception('config_directory is not set in the configurator array.');
        }

        if (!file_exists($cron_configuration['config_directory'])) {
            if (!mkdir($cron_configuration['config_directory'], 0777, true)) {
                throw new Exception('Failed to create config directory.');
            }
        }

        if (!file_exists($cron_configuration['config_directory'] . '/cron_config.json')) {
            if (!touch($cron_configuration['config_directory'] . '/cron_config.json')) {
                throw new Exception('Failed to create cron_config.json file.');
            }
        }

        //---check if run interval exists, it is required and must be a valid strtotime() value.
        if (!isset($cron_configuration['run_interval']) || false === strtotime($cron_configuration['run_interval'])) {
            throw new Exception('The run interval is required and should be a valid strtotime() value! If it is a daily cronjob, use "-24 hours".');
        }

        return new self($cron_configuration);
    }


    // ----- CRON & CONFIG FUNCTIONS -----


    /**
     * Determines if the cron job can run based on the current configuration and state.
     * Updates the configuration if the job can run, or exits if it cannot.
     *
     * @return bool True if the cron job can run, otherwise the script exits.
     */
    private function canItRun()
    {
        $now = date('Y-m-d H:i:s');
        $cron_config = $this->cron_config;

        if (!isset($cron_config['cron_run_log']['run-date'], $cron_config['cron_run_log']['run-status'])) {
            $this->logAction("Configuration is missing important keys. Exiting program.");
            exit("Configuration is missing important keys. Exiting program.");
        }

        if (
            $cron_config['cron_run_log']['run-status'] === 'finished' &&
            strtotime($cron_config['cron_run_log']['run-date']) < strtotime($this->run_interval, strtotime($now))
        ) {
            $cron_config['cron_run_log']['run-date'] = $now;
            $cron_config['cron_run_log']['run-status'] = "running";
            $this->cron_config = $cron_config;
            $this->saveConfig();
            $this->logAction('----------------------------------------------------------------------------', 'Success');
            $this->logAction('Cron has been initiated successfully.', 'Success');
            $this->logAction('----------------------------------------------------------------------------', 'Success');
            return true;
        } else if ($cron_config['cron_run_log']['run-status'] !== 'running') {
            $this->logAction('Cron already ran for the current interval! Exiting program.');
            exit('Cron already ran for the current interval! Exiting program.');
        }

        //---if reaching here, it means we're continuing an existing batch.
        return true;
    }

    /**
     * Creates the initial configuration file for the cron job.
     */
    private function createConfig()
    {
        $now = $this->today . ' ' . date('H:i:s');
        $cron_config_contents = [
            "cron_run_log" => [
                "run-date" => $now,
                "run-status" => "running"
            ]
        ];

        $file = fopen($this->config_file_path, 'w');
        if ($file) {
            fwrite($file, json_encode($cron_config_contents));
            fclose($file);
            $this->logAction('----------------------------------------------------------------------------', 'Success');
            $this->logAction('Cron Config has been created.', 'Success');
            $this->logAction('----------------------------------------------------------------------------', 'Success');
        } else {
            $this->logAction('Cron Config could not be created, exiting app..', 'Error');
            exit();
        }
    }

    /**
     * Loads the cron configuration from the configuration file.
     */
    private function loadConfig()
    {
        if (!file_exists($this->config_file_path) || !filesize($this->config_file_path) > 0) {
            $this->createConfig();
        }

        $this->cron_config = json_decode(file_get_contents($this->config_file_path), true);
    }

    /**
     * Saves the current cron configuration to the configuration file.
     */
    private function saveConfig()
    {
        file_put_contents($this->config_file_path, json_encode($this->cron_config));
    }

    /**
     * Iterates to the next task or ends the cron job if there are no more tasks.
     * If there are tasks, it redirects to the current script to process the next task.
     * If there are no tasks, it marks the cron job as finished and logs completion.
     */
    public function iterateOrDie()
    {
        if (!$this->hasAnyValues($this->cron_config['tasks'])) {
            $this->endCron();
        } else {
            ob_start();
            header('Location: ' . $_SERVER['REQUEST_URI']);

            //---check if any custom header is set
            if (isset($this->flags['iteration_custom_headers'])) {
                foreach ($this->flags['iteration_custom_headers'] as $header => $value) {
                    header($header . ': ' . $value);
                }
            }
            ob_end_flush();

            $this->logAction('Cron Iterated', 'Success');
            exit();
        }
    }

    /**
     * Marks the cron job as finished, cleans up tasks, and logs the completion.
     */
    protected function endCron()
    {
        $this->cron_config['cron_run_log']['run-status'] = 'finished';
        unset($this->cron_config['tasks']);
        $this->saveConfig();
        $this->logAction('----------------------------------------------------------------------------', 'Success');
        $this->logAction('Cron completed successfully', 'Success');
        $this->logAction('----------------------------------------------------------------------------', 'Success');

        //---if cron_mailer is not empty, send log mail.
        if (!empty($this->cron_mailer)) {
            $this->cronMailer();
        }

        $logs = $this->getLogs();
        echo '<pre>' . htmlspecialchars($logs) . '</pre>';

        exit();
    }


    // ----- TASK MANAGEMENT FUNCTIONS -----


    /**
     * Retrieves the list of tasks from the cron configuration.
     *
     * @param string $key Key to look for inside tasks array, defaults to false (false gets all tasks).
     * @return mixed An array of tasks if any exist, false otherwise.
     */
    public function getTasks($key = false)
    {
        if (isset($this->cron_config['tasks']) && $this->hasAnyValues($this->cron_config['tasks'])) {

            if ($key && isset($this->cron_config['tasks'][$key])) {
                return $this->cron_config['tasks'][$key];
            } else {
                return $this->cron_config['tasks'];
            }

        } else {
            return false;
        }
    }

    /**
     * Adds tasks to the cron configuration. Can optionally restrict adding tasks dynamically.
     *
     * @param mixed $tasks The tasks to add, either a single task or an array of tasks.
     * @throws Exception If dynamic task adding is not allowed.
     */
    public function addTasks($tasks)
    {
        //---check if dynamic task adding is allowed
        if (isset($this->cron_config['tasks']) && (!isset($this->flags['allowDynamicTasks']) || !$this->flags['allowDynamicTasks'])) {
            $this->logAction("Adding dynamic tasks is not allowed, cron terminated.");
            throw new Exception('Dynamic task adding is not allowed.');
        }

        if (is_array($tasks)) {
            foreach ($tasks as $key => $task) {
                $this->cron_config['tasks'][$key] = $task;
            }
        } else {
            $this->cron_config['tasks'][] = $tasks;
        }

        $this->saveConfig();

        $this->logAction('Tasks inserted successfully.', 'Success');
    }

    /**
     * Removes and returns the given number of tasks from the cron configuration. If a key is specified,
     * it pops tasks from that key. If the number of available tasks is less than the amount specified,
     * it returns the available tasks. If no tasks are available, it returns null.
     *
     * @param string $key Optional. The key of the task to pop from.
     * @param int $amount Optional. The number of tasks to pop.
     * @return mixed If amount is 1 and a task is available, the single task. If amount is greater than 1, an array of tasks, potentially empty. Returns null if no tasks are available.
     */
    public function popTask($key = false, $amount = 1)
    {
        if ($amount <= 0) {
            return null; // Invalid amount, return null immediately.
        }

        $tasks = [];
        if (!$key) {
            while ($amount-- > 0 && !empty($this->cron_config['tasks'])) {
                $task = array_pop($this->cron_config['tasks']);
                if ($task !== null) {
                    $tasks[] = $task;
                }
            }
        } else {
            if (!isset($this->cron_config['tasks'][$key]) || empty($this->cron_config['tasks'][$key])) {
                // Return null if no tasks are set for the specified key.
                return null;
            }
            while ($amount-- > 0 && $this->hasAnyValues($this->cron_config['tasks'][$key])) {
                $task = array_pop($this->cron_config['tasks'][$key]);
                if ($task !== null) {
                    $tasks[] = $task;
                }
            }
        }
        $this->saveConfig();

        if (empty($tasks)) {
            return null; // No tasks available.
        }

        return $amount == 1 && count($tasks) === 1 ? reset($tasks) : $tasks;
    }

    /**
     * Removes the task with the specified '(string)$key' and logs the action.
     * 
     * @param string $key The key to look for inside tasks array.
     * @param bool $die The program will throw an exception if key was not found in the tasks array.
     * @return void
     * @throws Exception If $die is true and the key does not exist in the tasks array.
     */
    public function removeTask($key, $die = false)
    {
        $this->logAction("Trying to remove task with key: '{$key}'", 'Info');

        if (!isset($this->cron_config['tasks'][$key])) {
            $message = "Key: '{$key}' does not exist in tasks array.";
            $this->logAction($message);

            if ($die) {
                $this->logAction($message . "The process has been terminated in order to prevent infinite loops.");
                throw new Exception($message);
            }
        } else {
            unset($this->cron_config['tasks'][$key]);
            $this->saveConfig();
            $this->logAction("Key: '{$key}' has been removed from tasks successfully", 'Success');
        }
    }


    // ----- LOGS FUNCTIONS -----

    /**
     * Logs an action to the log file with a timestamp and status.
     *
     * @param string $action The action to log.
     * @param string $status The status of the action, defaults to "Error".
     */
    public function logAction($action, $status = "Error")
    {
        $logTime = date("Y-m-d H:i:s");
        $maxStatusLength = 8;
        $padStatus = str_pad($status, $maxStatusLength);

        switch ($status) {
            case 'Error':
                $logEntry = "{$padStatus} !! {$logTime} !! {$action}\n";
                break;
            case 'Info':
                $logEntry = "{$padStatus} ?? {$logTime} ?? {$action}\n";
                break;
            default:
                $logEntry = "{$padStatus} -- {$logTime} -- {$action}\n";
                break;
        }

        file_put_contents($this->logs_file_path, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Retrieves log entries for a specified date. Can reverse the order and limit the number of entries.
     *
     * @param string|null $date The date for which to retrieve logs. Defaults to the current date.
     * @param bool $reverse Whether to reverse the order of log entries. Defaults to true.
     * @param int $limit The maximum number of log entries to retrieve. Defaults to no limit.
     * @return string The log entries as a string.
     * @throws Exception If no logs exist for the specified date.
     */
    public function getLogs($date = null, $reverse = true, $limit = PHP_INT_MAX)
    {

        if ($date === null) {
            $date = date("Y-m-d");
        }

        $path = dirname($this->logs_file_path) . '/' . $date . '.txt';

        if (!file_exists($path)) {
            throw new Exception("No logs for the specified date.");
        }

        $log_entries = file($path, FILE_IGNORE_NEW_LINES);
        if ($reverse) {
            $log_entries = array_reverse($log_entries);
        }

        if ($limit !== PHP_INT_MAX) {
            $log_entries = array_slice($log_entries, 0, $limit);
        }

        $log_entries_filtered = array_filter($log_entries, function ($entry) {
            return trim($entry) !== '';
        });

        $log_content = implode("\n", array_map('htmlspecialchars', $log_entries_filtered));

        return $log_content;
    }


    // ----- UTILITIES -----


    /**
     * Checks if an array has any values. Recursively checks nested arrays.
     *
     * @param array $array The array to check.
     * @return bool True if the array has any values, false otherwise.
     */
    protected function hasAnyValues($array)
    {
        foreach ($array as $value) {
            if (is_array($value)) {
                if ($this->hasAnyValues($value)) {
                    return true;
                }
            } else if (!empty($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tries to use PHPMailer and cron_mailer options to send logs to the specified address.
     *
     * @return void
     */
    protected function cronMailer()
    {
        $this->logAction("Mail options found, trying to send email using passed options and PHPMailer", "Info");

        // Check if PHPMailerDirPath is passed
        if (!isset($this->cron_mailer['PHPMailerDirPath'])) {
            $this->logAction("PHPMailerDirPath is not found, please pass the path to the PHPMailer directory", "Error");
            return;
        }

        // Remove the "/" from the end of path if it exists
        $phpMailerDirPath = rtrim($this->cron_mailer['PHPMailerDirPath'], '/');

        // Paths to PHPMailer files
        $phpMailerExceptionPath = $phpMailerDirPath . '/Exception.php';
        $phpMailerPath = $phpMailerDirPath . '/PHPMailer.php';
        $phpMailerSMTPPath = $phpMailerDirPath . '/SMTP.php';

        // Checks for PHPMailer file existence, logs specifically which files are missing
        $filesToCheck = [
            $phpMailerExceptionPath, $phpMailerPath, $phpMailerSMTPPath
        ];
        foreach ($filesToCheck as $filepath) {
            if (!file_exists($filepath)) {
                $this->logAction("PHPMailer file missing: {$filepath}", "Error");
                return;
            }
        }

        // Dynamically include PHPMailer files
        require_once ($phpMailerExceptionPath);
        require_once ($phpMailerPath);
        require_once ($phpMailerSMTPPath);

        // Instantiate PHPMailer
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            // Set mailer configurations from the $this->cron_mailer settings
            $mailer->isSMTP();
            $mailer->Host = $this->cron_mailer['Host'];
            $mailer->SMTPAuth = $this->cron_mailer['SMTPAuth'];
            $mailer->Username = $this->cron_mailer['Username'];
            $mailer->Password = $this->cron_mailer['Password'];
            $mailer->SMTPSecure = $this->cron_mailer['SMTPSecure'];
            $mailer->Port = $this->cron_mailer['Port'];
            $mailer->SMTPOptions = $this->cron_mailer['SMTPOptions'];

            $mailer->setFrom($this->cron_mailer['setFrom']['from_address'], $this->cron_mailer['setFrom']['from_name']);

            // Add all recipients
            foreach ($this->cron_mailer['addAddress'] as $address => $name) {
                $mailer->addAddress($address, $name);
            }

            // Email subject and body from log content
            $log_content = $this->getLogs();
            $mailer->isHTML(true);
            $mailer->Subject = $this->cron_mailer['Subject'];
            $mailer->Body = nl2br($log_content);
            $mailer->AltBody = strip_tags($log_content);

            // Send the email
            $mailer->send();
            $this->logAction('----------------------------------------------------------------------------', 'Success');
            $this->logAction('--------------------------- Email has been sent. ---------------------------', 'Success');
            $this->logAction('----------------------------------------------------------------------------', 'Success');

        } catch (\PHPMailer\PHPMailer\Exception $e) {

            $this->logAction("PHPMailer-specific error: " . $e->getMessage(), 'Error');

        } catch (\Exception $e) {

            $this->logAction("General error in sending email: " . $e->getMessage(), 'Error');

        }
    }
}

//////////////////////////////////////////////////////////////////////////////////

// USAGE EXAMPLE //

//////////////////////////////////////////////////////////////////////////////////

// 1 - Set Options

// $cron_configuration = array(
//     "config_directory" => "test/crontest/",
//     "logs_directory" => "test/crontest/logs",
//     "today" => date('Y-m-d'),
//     "run_interval" => "-23 hours"
// );
//
// $cron_flags = array( // - Adding this array to the configurator is completely optional.
//     "allow_dynamic_tasks" => false, // - Allowing dynamic tasks will let user add tasks after the initial tasks (allows adding tasks on top of old ones, possible infinite loop, use at your own risk!).
//     "iteration_custom_headers" => array( // - These headers will be sent to the server with each iteration.
//         "DEBUG" => $settings['site']['master_key'] // - This will be used as ```header('DEBUG: ' . $master_key)```
//     )
// );
// $cron_configuration['cron_flags'] = $cron_flags;

// - The class can also try to send logs as email at the end of the cron job if the below object is passed inside the configurator.

// $cron_mailer = array(
//     "Host" => "mail.host.co.za",
//     "Port" => 587,
//     "SMTPAuth" => true,
//     "SMTPOptions" => [
//         "ssl" => [
//             "verify_peer" => false,
//             "verify_peer_name" => false,
//             "allow_self_signed" => true
//         ]
//     ],
//     "SMTPSecure" => "tls",
//     "Username" => "username@username.com",
//     "Password" => "password",
//     "setFrom" => [
//         "from_name" => "name_of_sender",
//         "from_address" => "address_of_sender"
//     ],
//     "addAddress" => [
//         "recipient_address" => "recipient_name" //---name and address of recipients, accepts multiple
//     ],
//     "Subject" => "name_of_cronjob", //---subject of the mail
// );
// $cron_configuration['cron_mailer'] = $cron_mailer;

// ----------------------------------------------------------------------------------------------------------------------------

// 2 - Initiate cron class by using ::create

// $cronController = CronController::create($cron_configuration);

// ----------------------------------------------------------------------------------------------------------------------------

// 3 - Check if there are any tasks in the queue, the tasks should only be appended to the cron one time only. 
//      Otherwise the class might start an infinite loop.

// if (!$cronController->getTasks()) {
//     $tasks = array(
//         'task1',
//         'task2',
//         'task3'
//     );
//     $cronController->addTasks($tasks);
// }

// ----------------------------------------------------------------------------------------------------------------------------

// 4 - In each iteration the task must be popped from the class in order to be used.

// $task = $cronController->popTask(); //---customizations are available, please read function docs.
// // ---------- OR YOU CAN DO THIS MANUALLY ->>>>>
// $task = $cronController->getTasks("task-list-1"); //Afterwards manually remove the task-list-1, or you will create infinite loop.
// $cronController->removeTask("task-list-1", true); //Second parameter 'True' will kill process if task removal was unsuccessful.

// $cronController->logAction("the task of '$task' popped and ready to use", 'Success');

// ----------------------------------------------------------------------------------------------------------------------------

// 5 - After getting the task, the operation can be carried out.

// Some operations and codes....
// $cronController->logAction("Operation on '$task' is successfull", 'Success');

// ----------------------------------------------------------------------------------------------------------------------------

// 6 - Finalize or iterate the cron job by using the below function.

// $cronController->iterateOrDie(); //this will end the script if no tasks remaining.

// ----------------------------------------------------------------------------------------------------------------------------