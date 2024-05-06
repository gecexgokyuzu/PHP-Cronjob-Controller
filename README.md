# PHP-Cronjob-Controller

## About CronController
The configurators main purpose is to create request loops to bypass timeouts.
The CronController class manages the execution of cron jobs with a focus on easy integration, dynamic task management, and thorough logging. This PHP class provides a structured way to handle cron tasks by specifying execution intervals, implementing dynamic task addition, and performing logging for both monitoring and debugging. The objective is to offer a clean and reusable solution for managing scheduled tasks in PHP projects.

## Features

- **Dynamic Task Management**: Add, remove, or pop tasks dynamically based on real-time requirements.
- **Configurable Execution Intervals**: Specify how frequently your cron job should run, with easy configuration.
- **Detailed Logging**: Every action within the cron job, including errors, informational messages, and successes, is logged for reliable monitoring.
- **Mailer Integration**: After completion, the cron job can automatically send out an email with the log of its latest run, ensuring you stay updated on its performance and output.
- **Secure & Private**: Designed with privacy in mind, the class does not expose any functionality until explicitly invoked, preventing unauthorized use.

## Getting Started

To use the CronController class, clone this repository or copy the `CronController.php` file into your project.

```sh
git clone https://github.com/gecexgokyuzu/PHP-Cronjob-Controller
```

### Prerequisites

You should have PHP 7.1 or newer installed on your server to ensure compatibility with the class syntax and functionality.

### Installation

No specific installation procedure is needed apart from including the CronController class in your PHP script.

```php
require_once 'path/to/CronController.php';
```

### Configuration

Initialize and configure your cron job by preparing an array of settings for the CronController:

```php
$cron_configuration = [
    "config_directory" => "path/to/config/", // Directory for cron configuration
    "logs_directory" => "path/to/logs/", // Directory for logs
    "today" => date('Y-m-d'), // The current date, can be set to a specific date for testing
    "run_interval" => "-24 hours" // The interval at which the cron job should repeat
];
```

Specify optional flags for custom behavior:

```php
$cron_flags = [
    "allow_dynamic_tasks" => false, // Allow the dynamic addition of tasks after initial setting, could cause infinite loops!
    "iteration_custom_headers" => [ // Custom headers for each iteration, will be used as: ```header("CUSTOM_HEADER: " . $value);```
        "CUSTOM_HEADER" => "value"
    ]
];
$cron_configuration['cron_flags'] = $cron_flags;
```

Configure mailer settings to send logs via email upon completion:

```php
$cron_mailer = [
    // SMTP settings and recipients
];
$cron_configuration['cron_mailer'] = $cron_mailer;
```

### Usage

After setting your configuration, create and start your cron job:

```php
$cronController = CronController::create($cron_configuration);

// Add tasks if necessary
if (!$cronController->getTasks()) {
    $tasks = ['task1', 'task2', 'task3'];
    $cronController->addTasks($tasks);
}

// Pop and handle a task
$task = $cronController->popTask(); //There are multiple ways to do this according to your needs, check the class file for more examples!
// Perform operations...

// Log any actions or outcomes
$cronController->logAction("Performed operation on '$task'", 'Success');

// Iterate to the next task or finalize the cron job
$cronController->iterateOrDie(); //this will either send header() or die()
```

### Tips for Use

- Be careful with dynamic task additions; ensure proper validation to avoid infinite loops.
- Utilize the logging functionality to keep track of cron job performance and issues.
- Test your cron job's execution and error handling in a controlled environment before deploying to production.

## Contributing

Contributions are what make the open-source community such an amazing place to learn, inspire, and create. Any contributions you make are **greatly appreciated**.

Please feel free to fork the project, make changes, and submit pull requests.

## License

Distributed under the MIT License. See `LICENSE` for more information.

## Acknowledgements

This class is intended to help developers manage scheduled tasks more effectively. We hope it serves your projects well!

For any questions or suggestions, please open an issue on the GitHub repository.
