<?php

namespace IICN\Subscription\Commands;

use Carbon\Carbon;
use Google\Client;
use Google\Service\AndroidPublisher;
use IICN\Subscription\Constants\AgentType;
use IICN\Subscription\Constants\Status;
use IICN\Subscription\Models\SubscriptionTransaction;
use IICN\Subscription\Models\SubscriptionUser;
use Illuminate\Console\Command;

class CheckSubscriptionRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:check-renewals {--days=7 : Check subscriptions expiring within this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and update Google Play subscription renewals';

    protected ?AndroidPublisher $service = null;
    protected string $packageName;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        $this->info("Checking Google Play subscriptions expiring within {$days} days...");

        $transactions = $this->getActiveGooglePlaySubscriptions($days);

        if ($transactions->isEmpty()) {
            $this->info('No subscriptions to check.');
            return Command::SUCCESS;
        }

        $this->initializeGoogleClient();

        $updated = 0;
        $failed = 0;

        foreach ($transactions as $transaction) {
            try {
                if ($this->checkAndUpdateRenewal($transaction)) {
                    $updated++;
                    $this->info("Updated subscription for transaction ID: {$transaction->id}");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->error("Error checking transaction ID: {$transaction->id} - {$e->getMessage()}");
            }
        }

        $this->info("Completed. Updated: {$updated}, Failed: {$failed}");

        return Command::SUCCESS;
    }

    protected function getActiveGooglePlaySubscriptions(int $days)
    {
        return SubscriptionTransaction::query()
            ->where('agent_type', AgentType::GOOGLE_PLAY)
            ->where('status', Status::SUCCESS)
            ->whereNotNull('subscription_user_id')
            ->whereHas('subscriptionUser', function ($query) use ($days) {
                $query->where('expiry_at', '>', Carbon::now())
                    ->where('expiry_at', '<=', Carbon::now()->addDays($days));
            })
            ->with('subscriptionUser')
            ->get();
    }

    protected function initializeGoogleClient(): void
    {
        $this->packageName = config('subscription.google.package_name');

        $client = new Client();
        $client->setApplicationName($this->packageName);

        $authConfig = [
            "type" => "service_account",
            "project_id" => config('subscription.google.auth_config.project_id'),
            "private_key_id" => config('subscription.google.auth_config.private_key_id'),
            "private_key" => config('subscription.google.auth_config.private_key'),
            "client_email" => config('subscription.google.auth_config.client_email'),
            "client_id" => config('subscription.google.auth_config.client_id'),
            "auth_uri" => "https://accounts.google.com/o/oauth2/auth",
            "token_uri" => "https://oauth2.googleapis.com/token",
            "auth_provider_x509_cert_url" => "https://www.googleapis.com/oauth2/v1/certs",
            "client_x509_cert_url" => config('subscription.google.auth_config.client_x509_cert_url'),
        ];

        $client->setAuthConfig($authConfig);
        $client->addScope('https://www.googleapis.com/auth/androidpublisher');

        $this->service = new AndroidPublisher($client);
    }

    protected function checkAndUpdateRenewal(SubscriptionTransaction $transaction): bool
    {
        $result = $this->service->purchases_subscriptions->get(
            $this->packageName,
            $transaction->product_id,
            $transaction->purchase_token
        );

        $newExpiryDate = Carbon::createFromTimestampMs($result->getExpiryTimeMillis());
        $currentExpiryDate = Carbon::parse($transaction->subscriptionUser->expiry_at);

        // Check if subscription was renewed (new expiry is later than current)
        if ($newExpiryDate->gt($currentExpiryDate)) {
            $subscriptionUser = SubscriptionUser::find($transaction->subscription_user_id);

            if ($subscriptionUser) {
                $subscriptionUser->expiry_at = $newExpiryDate;
                $subscriptionUser->save();

                return true;
            }
        }

        // Check if subscription was cancelled or expired
        if ($result->getCancelReason() !== null || $newExpiryDate->lt(Carbon::now())) {
            $this->warn("Subscription cancelled or expired for transaction ID: {$transaction->id}");
        }

        return false;
    }
}
