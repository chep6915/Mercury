<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\FetchTelegramMessages;

// 引入自定義命令

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        FetchTelegramMessages::class, // 註冊自定義命令
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 調度命令，類似舊版的定時任務
        $schedule->command('telegram:fetch')->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        // 註冊 Laravel 的內建命令
        $this->load(__DIR__ . '/Commands');

//        require base_path('routes/console.php');
    }
}
