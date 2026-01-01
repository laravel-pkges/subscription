<?php

namespace IICN\Subscription\Http\Controllers\Webhook;

use Carbon\Carbon;
use Google\Client;
use Google\Service\AndroidPublisher;
use IICN\Subscription\Constants\AgentType;
use IICN\Subscription\Constants\Status;
use IICN\Subscription\Http\Controllers\Controller;
use IICN\Subscription\Models\SubscriptionTransaction;
use IICN\Subscription\Models\SubscriptionUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GooglePlayWebhookController extends Controller
{
    /**
     * Notification types from Google Play RTDN
     */
    const SUBSCRIPTION_RECOVERED = 1;
    const SUBSCRIPTION_RENEWED = 2;
    const SUBSCRIPTION_CANCELED = 3;
    const SUBSCRIPTION_PURCHASED = 4;
    const SUBSCRIPTION_ON_HOLD = 5;
    const SUBSCRIPTION_IN_GRACE_PERIOD = 6;
    const SUBSCRIPTION_RESTARTED = 7;
    const SUBSCRIPTION_PRICE_CHANGE_CONFIRMED = 8;
    const SUBSCRIPTION_DEFERRED = 9;
    const SUBSCRIPTION_PAUSED = 10;
    const SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED = 11;
    const SUBSCRIPTION_REVOKED = 12;
    const SUBSCRIPTION_EXPIRED = 13;

    protected ?AndroidPublisher $service = null;
    protected string $packageName;

    /**
     * Handle Google Play Real-Time Developer Notification
     */
    public function __invoke(Request $request): JsonResponse
    {
        $message = $request->input('message');

        if (!$message || !isset($message['data'])) {
            Log::warning('Google Play Webhook: Invalid message format', ['request' => $request->all()]);
            return response()->json(['error' => 'Invalid message format'], 400);
        }

        $data = json_decode(base64_decode($message['data']), true);

        if (!$data) {
            Log::warning('Google Play Webhook: Could not decode message data');
            return response()->json(['error' => 'Could not decode message'], 400);
        }

        Log::info('Google Play Webhook received', ['data' => $data]);

        // Handle subscription notifications
        if (isset($data['subscriptionNotification'])) {
            $this->handleSubscriptionNotification($data);
        }

        // Handle one-time product notifications
        if (isset($data['oneTimeProductNotification'])) {
            $this->handleOneTimeProductNotification($data);
        }

        // Acknowledge the message
        return response()->json(['success' => true]);
    }

    protected function handleSubscriptionNotification(array $data): void
    {
        $notification = $data['subscriptionNotification'];
        $packageName = $data['packageName'] ?? null;
        $purchaseToken = $notification['purchaseToken'] ?? null;
        $subscriptionId = $notification['subscriptionId'] ?? null;
        $notificationType = $notification['notificationType'] ?? null;

        if (!$purchaseToken || !$subscriptionId) {
            Log::warning('Google Play Webhook: Missing purchaseToken or subscriptionId');
            return;
        }

        Log::info("Google Play Webhook: Notification type {$notificationType} for subscription {$subscriptionId}");

        // Find existing transaction by purchase token
        $transaction = SubscriptionTransaction::query()
            ->where('purchase_token', $purchaseToken)
            ->where('agent_type', AgentType::GOOGLE_PLAY)
            ->where('status', Status::SUCCESS)
            ->whereNotNull('subscription_user_id')
            ->first();

        if (!$transaction) {
            Log::info('Google Play Webhook: No matching transaction found', [
                'purchaseToken' => substr($purchaseToken, 0, 20) . '...',
                'subscriptionId' => $subscriptionId
            ]);
            return;
        }

        switch ($notificationType) {
            case self::SUBSCRIPTION_RENEWED:
            case self::SUBSCRIPTION_RECOVERED:
            case self::SUBSCRIPTION_RESTARTED:
                $this->updateSubscriptionExpiry($transaction, $packageName ?? $this->getPackageName(), $subscriptionId, $purchaseToken);
                break;

            case self::SUBSCRIPTION_CANCELED:
            case self::SUBSCRIPTION_REVOKED:
            case self::SUBSCRIPTION_EXPIRED:
                $this->markSubscriptionExpired($transaction);
                break;

            case self::SUBSCRIPTION_ON_HOLD:
            case self::SUBSCRIPTION_PAUSED:
                Log::info("Subscription paused/on-hold for transaction ID: {$transaction->id}");
                break;

            case self::SUBSCRIPTION_IN_GRACE_PERIOD:
                Log::info("Subscription in grace period for transaction ID: {$transaction->id}");
                break;

            default:
                Log::info("Unhandled notification type: {$notificationType}");
        }
    }

    protected function handleOneTimeProductNotification(array $data): void
    {
        // One-time products don't renew, just log for now
        Log::info('Google Play Webhook: One-time product notification', ['data' => $data]);
    }

    protected function updateSubscriptionExpiry(
        SubscriptionTransaction $transaction,
        string $packageName,
        string $subscriptionId,
        string $purchaseToken
    ): void {
        try {
            $this->initializeGoogleClient();

            $result = $this->service->purchases_subscriptions->get(
                $packageName,
                $subscriptionId,
                $purchaseToken
            );

            $newExpiryDate = Carbon::createFromTimestampMs($result->getExpiryTimeMillis());

            $subscriptionUser = SubscriptionUser::find($transaction->subscription_user_id);

            if ($subscriptionUser) {
                $oldExpiry = $subscriptionUser->expiry_at;
                $subscriptionUser->expiry_at = $newExpiryDate;
                $subscriptionUser->save();

                Log::info("Subscription renewed for transaction ID: {$transaction->id}", [
                    'old_expiry' => $oldExpiry,
                    'new_expiry' => $newExpiryDate->toDateTimeString()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to update subscription expiry for transaction ID: {$transaction->id}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function markSubscriptionExpired(SubscriptionTransaction $transaction): void
    {
        $subscriptionUser = SubscriptionUser::find($transaction->subscription_user_id);

        if ($subscriptionUser && $subscriptionUser->expiry_at > Carbon::now()) {
            // Set expiry to now if it was cancelled/revoked before natural expiry
            $subscriptionUser->expiry_at = Carbon::now();
            $subscriptionUser->save();

            Log::info("Subscription marked as expired for transaction ID: {$transaction->id}");
        }
    }

    protected function initializeGoogleClient(): void
    {
        if ($this->service !== null) {
            return;
        }

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

    protected function getPackageName(): string
    {
        return config('subscription.google.package_name');
    }
}
