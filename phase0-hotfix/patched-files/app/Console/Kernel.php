<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\AutoPurchase::class,
        Commands\DsoAlert::class,
        Commands\ResetDB::class,
        Commands\ReviewMemberships::class,
        Commands\ExpireMemberships::class,
        Commands\RefreshMembershipBenefits::class,
        Commands\QueueMembershipRenewalReminders::class,
        Commands\CaptureMembershipStatistics::class,
        Commands\ResetMembershipAnnualPurchases::class,
        Commands\ProcessRewards::class,
        Commands\GenerateAiDraftOrders::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // PHASE 0 HOTFIX: removed demo/test tasks that must never run in production:
        //  - reset:db      truncated EVERY table every minute (SalePro demo reset)
        //  - quote:daily   test mail every minute
        //  - purchase:auto created an automatic purchase every 5 minutes for low-stock
        //                  products (re-enable only after a business decision)
        $schedule->command('dsoalert:find')->dailyAt('00:00');
        $schedule->command('membership:review')->dailyAt('01:15')->withoutOverlapping();
        $schedule->command('membership:expire')->dailyAt('01:45')->withoutOverlapping();
        $schedule->command('membership:benefits-refresh')->dailyAt('02:00')->withoutOverlapping();
        $schedule->command('membership:renewal-reminders')->hourly()->withoutOverlapping();
        $schedule->command('membership:statistics')->monthlyOn(1, '02:30')->withoutOverlapping();
        $schedule->command('membership:annual-reset')->yearlyOn(1, 1, '00:15')->withoutOverlapping();
        $schedule->command('rewards:process')->hourly()->withoutOverlapping();
        $schedule->command('orders:generate-ai-drafts')
            ->dailyAt(config('services.openai.order_generation_time', '02:30'))
            ->withoutOverlapping(360);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
