<?php

namespace App\Support;

/**
 * Fixed catalog of transactional email events and default copy.
 * Layout (logo / theme colours) is always applied by branded mail views.
 */
class EmailTemplateCatalog
{
    public const AUDIENCE_USER = 'user';

    public const AUDIENCE_ADMIN = 'admin';

    public const EDITABLE_KEYS = [
        'subject',
        'eyebrow',
        'heading',
        'intro',
        'closing',
        'cta_label',
    ];

    /**
     * @return array<string, array{
     *   label: string,
     *   description: string,
     *   group: string,
     *   requires_any: ?list<string>,
     *   requires_module: ?string,
     *   requires_private: bool,
     *   requires_public: bool,
     *   audiences: list<string>,
     *   variables: list<array{key: string, label: string}>,
     *   defaults: array<string, array{subject: string, eyebrow: string, heading: string, intro: string, closing: ?string, cta_label: ?string}>
     * }>
     */
    public static function events(): array
    {
        return [
            'user_registered' => [
                'label' => 'User registered',
                'description' => 'Welcome email to the new member and admin notification when someone registers or is created.',
                'group' => 'Accounts',
                'requires_any' => null,
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER, self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'User name'],
                    ['key' => 'user_email', 'label' => 'User email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'support_email', 'label' => 'Support email'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Welcome to {{site_name}}!',
                        'eyebrow' => 'Welcome',
                        'heading' => 'Welcome to {{site_name}}!',
                        'intro' => "Hi {{user_name}},\n\nThank you for verifying your email and joining {{site_name}}! We’re thrilled to have you on board.",
                        'closing' => 'We look forward to being the trusted partner in your journey of growth. For any assistance you can always reach us at {{support_email}}.',
                        'cta_label' => 'Log in',
                    ],
                    self::AUDIENCE_ADMIN => [
                        'subject' => '[{{site_name}}] New User Registration',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'New user registration',
                        'intro' => 'A new user has registered on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => null,
                    ],
                ],
            ],

            'order_confirmed' => [
                'label' => 'Order confirmed',
                'description' => 'Receipt sent to the purchaser after a subscription, post, or bundle purchase.',
                'group' => 'Purchases',
                'requires_any' => ['paid_credits', 'unlimited_credits', 'one_off_purchase', 'public_subscribe'],
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Purchaser name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'invoice_number', 'label' => 'Invoice number'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'support_email', 'label' => 'Support email'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Your {{site_name}} Order is Confirmed!',
                        'eyebrow' => 'Order confirmed',
                        'heading' => 'Your order is confirmed',
                        'intro' => "Hi {{user_name}},\n\nThank you for your purchase on {{site_name}}. Your order details are below.",
                        'closing' => 'Questions? Contact {{support_email}}.',
                        'cta_label' => 'View invoice',
                    ],
                ],
            ],

            'post_purchased' => [
                'label' => 'Post / bundle purchased',
                'description' => 'Admin notification when a post or bundle download is purchased.',
                'group' => 'Purchases',
                'requires_any' => ['one_off_purchase', 'paid_credits', 'unlimited_credits'],
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'invoice_number', 'label' => 'Invoice / payment ID'],
                    ['key' => 'purchaser_name', 'label' => 'Purchaser name'],
                    ['key' => 'purchaser_email', 'label' => 'Purchaser email'],
                    ['key' => 'amount', 'label' => 'Amount'],
                ],
                'defaults' => [
                    self::AUDIENCE_ADMIN => [
                        'subject' => 'New download purchase - Order #{{invoice_number}}',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'New download purchase',
                        'intro' => 'A post or bundle download was purchased on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => null,
                    ],
                ],
            ],

            'subscription_paid' => [
                'label' => 'Subscription paid',
                'description' => 'Admin notification when a plan subscription payment is received.',
                'group' => 'Purchases',
                'requires_any' => ['public_subscribe', 'paid_credits'],
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => true,
                'audiences' => [self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'user_name', 'label' => 'Purchaser name'],
                    ['key' => 'user_email', 'label' => 'Purchaser email'],
                    ['key' => 'plan_name', 'label' => 'Plan name'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'invoice_number', 'label' => 'Invoice number'],
                ],
                'defaults' => [
                    self::AUDIENCE_ADMIN => [
                        'subject' => '[{{site_name}}] New subscription payment',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'New subscription payment',
                        'intro' => 'A plan subscription payment was received on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => 'View invoice',
                    ],
                ],
            ],

            'advisor_billing_paid' => [
                'label' => 'Advisor billing paid',
                'description' => 'Receipt to the payer and admin notification when advisor billing is paid.',
                'group' => 'Advisor billing',
                'requires_any' => ['advisor_subscriber_billing'],
                'requires_module' => null,
                'requires_private' => true,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER, self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Payer name'],
                    ['key' => 'user_email', 'label' => 'Payer email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'invoice_number', 'label' => 'Invoice number'],
                    ['key' => 'advisor_count', 'label' => 'Advisor count'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Your {{site_name}} advisor billing receipt',
                        'eyebrow' => 'Payment received',
                        'heading' => 'Advisor billing payment confirmed',
                        'intro' => "Hi {{user_name}},\n\nWe’ve received your advisor billing payment for {{site_name}}.",
                        'closing' => 'Thank you for your payment.',
                        'cta_label' => 'View invoice',
                    ],
                    self::AUDIENCE_ADMIN => [
                        'subject' => '[{{site_name}}] Advisor billing paid',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'Advisor billing payment received',
                        'intro' => 'An advisor billing payment was received on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => 'View invoice',
                    ],
                ],
            ],

            'bank_transfer_subscription_pending' => [
                'label' => 'Bank transfer subscription pending',
                'description' => 'Instructions to the member and admin alert when a subscription bank transfer is pending.',
                'group' => 'Purchases',
                'requires_any' => ['public_subscribe', 'paid_credits'],
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => true,
                'audiences' => [self::AUDIENCE_USER, self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Member name'],
                    ['key' => 'user_email', 'label' => 'Member email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'plan_name', 'label' => 'Plan name'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'payment_reference', 'label' => 'Payment reference'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Complete your bank transfer',
                        'eyebrow' => 'Payment pending',
                        'heading' => 'Bank transfer instructions',
                        'intro' => "Hi {{user_name}},\n\nYour subscription order is pending. Please complete the bank transfer using the details below.",
                        'closing' => 'Include the payment reference exactly so we can match your payment.',
                        'cta_label' => 'Log in',
                    ],
                    self::AUDIENCE_ADMIN => [
                        'subject' => '[{{site_name}}] Pending bank transfer subscription',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'Pending subscription bank transfer',
                        'intro' => 'A member started a bank transfer subscription on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => null,
                    ],
                ],
            ],

            'bank_transfer_advisor_billing_pending' => [
                'label' => 'Bank transfer advisor billing pending',
                'description' => 'Instructions to the payer and admin alert when advisor billing bank transfer is pending.',
                'group' => 'Advisor billing',
                'requires_any' => ['advisor_subscriber_billing'],
                'requires_module' => null,
                'requires_private' => true,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER, self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Payer name'],
                    ['key' => 'user_email', 'label' => 'Payer email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'advisor_count', 'label' => 'Advisor count'],
                    ['key' => 'payment_reference', 'label' => 'Payment reference'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Advisor billing bank transfer',
                        'eyebrow' => 'Payment pending',
                        'heading' => 'Advisor billing transfer instructions',
                        'intro' => "Hi {{user_name}},\n\nYour advisor billing payment is pending. Please complete the bank transfer using the details below.",
                        'closing' => 'Include the payment reference exactly so we can match your payment.',
                        'cta_label' => null,
                    ],
                    self::AUDIENCE_ADMIN => [
                        'subject' => '[{{site_name}}] Pending advisor billing transfer',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'Pending advisor billing bank transfer',
                        'intro' => 'An advisor billing bank transfer is awaiting confirmation on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => null,
                    ],
                ],
            ],

            'advisor_invite' => [
                'label' => 'Advisor invite',
                'description' => 'Welcome email when an advisor account is created via import.',
                'group' => 'Advisors',
                'requires_any' => ['private_invite_only'],
                'requires_module' => null,
                'requires_private' => true,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Advisor name'],
                    ['key' => 'user_email', 'label' => 'Advisor email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'temporary_password', 'label' => 'Temporary password (if set)'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Welcome to {{site_name}} — your advisor access',
                        'eyebrow' => 'Advisor invite',
                        'heading' => "You're invited to {{site_name}}",
                        'intro' => "Hi {{user_name}},\n\nAn advisor account has been created for you on {{site_name}}.",
                        'closing' => null,
                        'cta_label' => 'Log in',
                    ],
                ],
            ],

            'advisor_reactivated' => [
                'label' => 'Advisor access restored',
                'description' => 'Sent when an advisor’s access is restored.',
                'group' => 'Advisors',
                'requires_any' => ['private_invite_only'],
                'requires_module' => null,
                'requires_private' => true,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Advisor name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Your advisor access was restored',
                        'eyebrow' => 'Access restored',
                        'heading' => 'Advisor access restored',
                        'intro' => "Hi {{user_name}},\n\nYour advisor access on {{site_name}} has been restored. You can log in again.",
                        'closing' => null,
                        'cta_label' => 'Log in',
                    ],
                ],
            ],

            'advisor_discontinued' => [
                'label' => 'Advisor discontinued',
                'description' => 'Notice to the advisor and admins when advisor access ends.',
                'group' => 'Advisors',
                'requires_any' => ['private_invite_only'],
                'requires_module' => null,
                'requires_private' => true,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER, self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Advisor name'],
                    ['key' => 'user_email', 'label' => 'Advisor email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'support_email', 'label' => 'Support email'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Your advisor access has ended',
                        'eyebrow' => 'Access ended',
                        'heading' => 'Advisor access discontinued',
                        'intro' => "Hi {{user_name}},\n\nYour advisor access on {{site_name}} has been discontinued. You will no longer be able to sign in.",
                        'closing' => 'If you think this was a mistake, contact {{support_email}}.',
                        'cta_label' => null,
                    ],
                    self::AUDIENCE_ADMIN => [
                        'subject' => '[{{site_name}}] Advisor discontinued',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'Advisor discontinued',
                        'intro' => 'An advisor was discontinued on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => null,
                    ],
                ],
            ],

            'advisor_suspended' => [
                'label' => 'Advisor suspended (public mode)',
                'description' => 'Sent when invite-only advisor access is suspended because the hub switched to public mode.',
                'group' => 'Advisors',
                'requires_any' => ['private_invite_only'],
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'Advisor name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'support_email', 'label' => 'Support email'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Advisor access suspended',
                        'eyebrow' => 'Access suspended',
                        'heading' => 'Advisor access temporarily suspended',
                        'intro' => "Hi {{user_name}},\n\n{{site_name}} is now operating in public mode. Your invite-only advisor access has been suspended.",
                        'closing' => 'Contact {{support_email}} if you need help.',
                        'cta_label' => null,
                    ],
                ],
            ],

            'advisor_import_summary' => [
                'label' => 'Advisor import completed',
                'description' => 'Admin summary after an advisor Excel/CSV import finishes.',
                'group' => 'Advisors',
                'requires_any' => ['private_invite_only'],
                'requires_module' => null,
                'requires_private' => true,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'created_count', 'label' => 'Created count'],
                    ['key' => 'reactivated_count', 'label' => 'Reactivated count'],
                ],
                'defaults' => [
                    self::AUDIENCE_ADMIN => [
                        'subject' => '[{{site_name}}] Advisor import completed',
                        'eyebrow' => 'Admin notification',
                        'heading' => 'Advisor import summary',
                        'intro' => 'An advisor Excel/CSV import finished on {{site_name}}.',
                        'closing' => null,
                        'cta_label' => null,
                    ],
                ],
            ],

            'account_created_by_admin' => [
                'label' => 'Account created by admin',
                'description' => 'Welcome email when an administrator creates a user account.',
                'group' => 'Accounts',
                'requires_any' => null,
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'User name'],
                    ['key' => 'user_email', 'label' => 'User email'],
                    ['key' => 'role_label', 'label' => 'Role label'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Welcome to {{site_name}}!',
                        'eyebrow' => 'Account created',
                        'heading' => 'Your {{site_name}} account is ready',
                        'intro' => "Hi {{user_name}},\n\nAn administrator created an account for you on {{site_name}}.",
                        'closing' => null,
                        'cta_label' => 'Log in',
                    ],
                ],
            ],

            'account_suspended_by_admin' => [
                'label' => 'Account suspended by admin',
                'description' => 'Notice when an administrator suspends a user account.',
                'group' => 'Accounts',
                'requires_any' => null,
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'User name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'support_email', 'label' => 'Support email'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Account suspended',
                        'eyebrow' => 'Account update',
                        'heading' => 'Your account has been suspended',
                        'intro' => "Hi {{user_name}},\n\nYour account on {{site_name}} has been suspended. You will not be able to sign in until an administrator restores access.",
                        'closing' => 'Questions? Contact {{support_email}}.',
                        'cta_label' => null,
                    ],
                ],
            ],

            'password_changed_by_admin' => [
                'label' => 'Password changed by admin',
                'description' => 'Security notice when an administrator changes a user’s password.',
                'group' => 'Accounts',
                'requires_any' => null,
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'User name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'support_email', 'label' => 'Support email'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Your password was changed',
                        'eyebrow' => 'Security',
                        'heading' => 'Password updated',
                        'intro' => "Hi {{user_name}},\n\nAn administrator updated the password for your {{site_name}} account. If you did not expect this, contact support immediately.",
                        'closing' => 'Support: {{support_email}}',
                        'cta_label' => 'Log in',
                    ],
                ],
            ],

            'password_reset' => [
                'label' => 'Password reset',
                'description' => 'Sent when a user requests a password reset link.',
                'group' => 'Accounts',
                'requires_any' => null,
                'requires_module' => null,
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'user_name', 'label' => 'User name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => '[{{site_name}}] Reset your password',
                        'eyebrow' => 'Security',
                        'heading' => 'Reset your password',
                        'intro' => "Hi {{user_name}},\n\nWe received a request to reset your password for {{site_name}}.",
                        'closing' => 'If you did not request this, you can ignore this email. This link expires soon.',
                        'cta_label' => 'Reset password',
                    ],
                ],
            ],

            'smc_request_submitted' => [
                'label' => 'Social media compliance — request submitted',
                'description' => 'Admin notification when a social media compliance request is submitted.',
                'group' => 'Social Media Compliance',
                'requires_any' => null,
                'requires_module' => 'module_social_media_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'user_name', 'label' => 'Submitter name'],
                    ['key' => 'user_email', 'label' => 'Submitter email'],
                ],
                'defaults' => [
                    self::AUDIENCE_ADMIN => [
                        'subject' => 'New Social Media Compliance Request Submitted',
                        'eyebrow' => 'Social Media Compliance',
                        'heading' => 'New social media compliance request awaiting assignment',
                        'intro' => 'A new social media compliance request has been submitted and requires assignment to a reviewer.',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],

            'smc_approver_assigned' => [
                'label' => 'Social media compliance — assigned for review',
                'description' => 'Sent to the approver when a request is assigned.',
                'group' => 'Social Media Compliance',
                'requires_any' => null,
                'requires_module' => 'module_social_media_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'assigned_by', 'label' => 'Assigned by'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Social Media Compliance Request Assigned for Review',
                        'eyebrow' => 'Social Media Compliance',
                        'heading' => 'A social media compliance request was assigned to you',
                        'intro' => 'Please review the submitted material and provide your assessment.',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],

            'smc_status_updated' => [
                'label' => 'Social media compliance — status updated',
                'description' => 'Sent to the subscriber when a review decision is made.',
                'group' => 'Social Media Compliance',
                'requires_any' => null,
                'requires_module' => 'module_social_media_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'status', 'label' => 'Decision status'],
                    ['key' => 'status_message', 'label' => 'Decision message'],
                    ['key' => 'reviewer_name', 'label' => 'Reviewer name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Update on Your Social Media Compliance Request',
                        'eyebrow' => 'Social Media Compliance',
                        'heading' => 'Social media compliance decision: {{status}}',
                        'intro' => '{{status_message}}',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],

            'smc_request_resubmitted' => [
                'label' => 'Social media compliance — resubmitted',
                'description' => 'Sent to the approver when a request is resubmitted.',
                'group' => 'Social Media Compliance',
                'requires_any' => null,
                'requires_module' => 'module_social_media_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'user_name', 'label' => 'Resubmitter name'],
                    ['key' => 'user_email', 'label' => 'Resubmitter email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Social Media Compliance Request Resubmitted for Review',
                        'eyebrow' => 'Social Media Compliance',
                        'heading' => 'A social media compliance request was resubmitted',
                        'intro' => 'The adviser has updated and resubmitted a previously returned request for your review.',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],

            'gc_request_submitted' => [
                'label' => 'General compliance — request submitted',
                'description' => 'Admin notification when a general compliance request is submitted.',
                'group' => 'General Compliance',
                'requires_any' => null,
                'requires_module' => 'module_general_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_ADMIN],
                'variables' => [
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'user_name', 'label' => 'Submitter name'],
                    ['key' => 'user_email', 'label' => 'Submitter email'],
                ],
                'defaults' => [
                    self::AUDIENCE_ADMIN => [
                        'subject' => 'New General Compliance Request Submitted',
                        'eyebrow' => 'General Compliance',
                        'heading' => 'New general compliance request awaiting assignment',
                        'intro' => 'A new general compliance request has been submitted and requires assignment to a reviewer.',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],

            'gc_approver_assigned' => [
                'label' => 'General compliance — assigned for review',
                'description' => 'Sent to the approver when a request is assigned.',
                'group' => 'General Compliance',
                'requires_any' => null,
                'requires_module' => 'module_general_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'assigned_by', 'label' => 'Assigned by'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'General Compliance Request Assigned for Review',
                        'eyebrow' => 'General Compliance',
                        'heading' => 'A general compliance request was assigned to you',
                        'intro' => 'Please review the submitted material and provide your assessment.',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],

            'gc_status_updated' => [
                'label' => 'General compliance — status updated',
                'description' => 'Sent to the subscriber when a review decision is made.',
                'group' => 'General Compliance',
                'requires_any' => null,
                'requires_module' => 'module_general_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'status', 'label' => 'Decision status'],
                    ['key' => 'status_message', 'label' => 'Decision message'],
                    ['key' => 'reviewer_name', 'label' => 'Reviewer name'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'Update on Your General Compliance Request',
                        'eyebrow' => 'General Compliance',
                        'heading' => 'General compliance decision: {{status}}',
                        'intro' => '{{status_message}}',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],

            'gc_request_resubmitted' => [
                'label' => 'General compliance — resubmitted',
                'description' => 'Sent to the approver when a request is resubmitted.',
                'group' => 'General Compliance',
                'requires_any' => null,
                'requires_module' => 'module_general_compliance',
                'requires_private' => false,
                'requires_public' => false,
                'audiences' => [self::AUDIENCE_USER],
                'variables' => [
                    ['key' => 'request_id', 'label' => 'Request ID'],
                    ['key' => 'user_name', 'label' => 'Resubmitter name'],
                    ['key' => 'user_email', 'label' => 'Resubmitter email'],
                    ['key' => 'site_name', 'label' => 'Hub / site name'],
                ],
                'defaults' => [
                    self::AUDIENCE_USER => [
                        'subject' => 'General Compliance Request Resubmitted for Review',
                        'eyebrow' => 'General Compliance',
                        'heading' => 'A general compliance request was resubmitted',
                        'intro' => 'The adviser has updated and resubmitted a previously returned request for your review.',
                        'closing' => null,
                        'cta_label' => 'View request',
                    ],
                ],
            ],
        ];
    }

    public static function event(string $key): ?array
    {
        return self::events()[$key] ?? null;
    }

    /**
     * @return array{subject: string, eyebrow: string, heading: string, intro: string, closing: ?string, cta_label: ?string}
     */
    public static function defaults(string $eventKey, string $audience): array
    {
        $event = self::event($eventKey);
        if ($event === null || ! isset($event['defaults'][$audience])) {
            throw new \InvalidArgumentException("Unknown email template \"{$eventKey}\" / \"{$audience}\".");
        }

        return $event['defaults'][$audience];
    }
}
