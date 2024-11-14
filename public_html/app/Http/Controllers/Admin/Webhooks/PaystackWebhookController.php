<?php

namespace App\Http\Controllers\Admin\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Events\PaymentProcessed;
use App\Events\PaymentReferrerBonus;
use App\Models\Subscriber;
use App\Models\SubscriptionPlan;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;

class PaystackWebhookController extends Controller
{
    /**
     * Stripe Webhook processing, unless you are familiar with 
     * Stripe's PHP API, we recommend not to modify it
     */
    public function handlePaystack(Request $request)
    {
        // only a post with paystack signature header gets our attention
        if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST' ) || !array_key_exists('x-paystack-signature', $_SERVER) ) {
            exit();
        }
            
        // Retrieve the request's body
        $input = @file_get_contents("php://input");

        define('PAYSTACK_SECRET_KEY', config('services.paystack.api_secret'));

        // validate event do all at once to avoid timing attack
        if($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, PAYSTACK_SECRET_KEY)) {
            exit();
        }
            
        http_response_code(200);

        // parse event (which is json string) as object
        // Do something - that will not take long - with $event
        $event = json_decode($input);

        switch ($event->event) {
            case 'subscription.disable': 
                $subscription = Subscriber::where('subscription_id', $event->data->subscription_code)->firstOrFail();
                $subscription->update(['status'=>'Cancelled', 'active_until' => Carbon::createFromFormat('Y-m-d H:i:s', now())->endOfMonth()]);

                $user = User::where('id', $subscription->user_id)->firstOrFail();
                $group = ($user->hasRole('admin'))? 'admin' : 'user';
                if ($group == 'user' || $group = 'subscriber') {
                    $user->syncRoles($group);    
                    $user->group = $group;
                    $user->plan_id = null;
                    $user->member_limit = null;
                    $user->save();
                } else {
                    $user->syncRoles($group);    
                    $user->group = $group;
                    $user->plan_id = null;
                    $user->save();
                }
                       
    
                break;
            case 'charge.success':
                $subscription = Subscriber::where('subscription_id', $event->data->subscription_code)->firstOrFail();

                if ($subscription) {
                    $plan = SubscriptionPlan::where('id', $subscription->plan_id)->firstOrFail();
                    $duration = ($plan->payment_frequency == 'monthly') ? 30 : 365;

                    $subscription->update([
                        'status' => 'Active', 
                        'active_until' => Carbon::now()->addDays($duration)
                    ]);
                    
                    $user = User::where('id', $subscription->user_id)->firstOrFail();

                    $tax_value = (config('payment.payment_tax') > 0) ? $plan->price * config('payment.payment_tax') / 100 : 0;
                    $total_price = $tax_value + $plan->price;

                    if (config('payment.referral.enabled') == 'on') {
                        if (config('payment.referral.payment.policy') == 'first') {
                            if (Payment::where('user_id', $user->id)->where('status', 'completed')->exists()) {
                                /** User already has at least 1 payment */
                            } else {
                                event(new PaymentReferrerBonus($user, $subscription->plan_id, $total_price, 'Paystack'));
                            }
                        } else {
                            event(new PaymentReferrerBonus($user, $subscription->plan_id, $total_price, 'Paystack'));
                        }
                    }

                    $record_payment = new Payment();
                    $record_payment->user_id = $user->id;
                    $record_payment->plan_id = $plan->id;
                    $record_payment->order_id = $subscription->plan_id;
                    $record_payment->plan_name = $plan->plan_name;
                    $record_payment->price = $total_price;
                    $record_payment->currency = $plan->currency;
                    $record_payment->gateway = 'Paystack';
                    $record_payment->frequency = $plan->payment_frequency;
                    $record_payment->status = 'completed';
                    $record_payment->gpt_3_turbo_credits = $plan->gpt_3_turbo_credits;
                    $record_payment->gpt_4_turbo_credits = $plan->gpt_4_turbo_credits;
                    $record_payment->gpt_4_credits = $plan->gpt_4_credits;
                    $record_payment->gpt_4o_credits = $plan->gpt_4o_credits;
                    $record_payment->gpt_4o_mini_credits = $plan->gpt_4o_mini_credits;
                    $record_payment->claude_3_opus_credits = $plan->claude_3_opus_credits;
                    $record_payment->claude_3_sonnet_credits = $plan->claude_3_sonnet_credits;
                    $record_payment->claude_3_haiku_credits = $plan->claude_3_haiku_credits;
                    $record_payment->gemini_pro_credits = $plan->gemini_pro_credits;
                    $record_payment->fine_tune_credits = $plan->fine_tune_credits;
                    $record_payment->dalle_images = $plan->dalle_images;
                    $record_payment->sd_images = $plan->sd_images;
                    $record_payment->save();
                    
                    $group = ($user->hasRole('admin')) ? 'admin' : 'subscriber';

                    $user->syncRoles($group);    
                    $user->group = $group;
                    $user->plan_id = $plan->id;
                    $user->gpt_3_turbo_credits = $plan->gpt_3_turbo_credits;
                    $user->gpt_4_turbo_credits = $plan->gpt_4_turbo_credits;
                    $user->gpt_4_credits = $plan->gpt_4_credits;
                    $user->gpt_4o_credits = $plan->gpt_4o_credits;
                    $user->gpt_4o_mini_credits = $plan->gpt_4o_mini_credits;
                    $user->claude_3_opus_credits = $plan->claude_3_opus_credits;
                    $user->claude_3_sonnet_credits = $plan->claude_3_sonnet_credits;
                    $user->claude_3_haiku_credits = $plan->claude_3_haiku_credits;
                    $user->gemini_pro_credits = $plan->gemini_pro_credits;
                    $user->fine_tune_credits = $plan->fine_tune_credits;
                    $user->available_chars = $plan->characters;
                    $user->available_minutes = $plan->minutes;
                    $user->member_limit = $plan->team_members;                    
                    $user->available_dalle_images = $plan->dalle_images;
                    $user->available_sd_images = $plan->sd_images;
                    $user->save();       

                    event(new PaymentProcessed($user));
                }                
          
                break;
        }
    }
}