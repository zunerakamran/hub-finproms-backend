<?php

namespace App\Support;

use App\Models\Hub;

/**
 * Default Terms & Conditions HTML per hub type.
 * Capable admins can replace this via the dashboard editor.
 */
class TermsAndConditionsDefaults
{
    public static function forType(string $type): string
    {
        return match ($type) {
            Hub::TYPE_CENTRAL => self::central(),
            Hub::TYPE_WHITE_LABEL => self::whiteLabel(),
            default => self::shared(),
        };
    }

    public static function shared(): string
    {
        return <<<'HTML'
<h2>Terms &amp; Conditions — Shared Hub</h2>
<p>Welcome to this FinProms Shared Hub. By creating an account or signing in, you agree to these Terms &amp; Conditions. Please read them carefully. A hub administrator may update this text at any time; you may be asked to accept the latest version again.</p>
<h3>1. Purpose of this hub</h3>
<p>This Shared Hub provides access to a content catalogue (such as social media posts and reels), subscription plans, credits, and related downloads for authorised members.</p>
<h3>2. Accounts and security</h3>
<p>You are responsible for keeping your login details confidential and for activity under your account. Notify the hub administrator promptly if you suspect unauthorised access.</p>
<h3>3. Subscriptions, credits and purchases</h3>
<p>Credits and subscriptions are subject to the plans and prices shown on this hub. Purchased or allotted credits may be used to unlock content in line with the hub’s rules. Fees are generally non-refundable except where required by law or stated otherwise by the hub operator.</p>
<h3>4. Content use</h3>
<p>Content obtained through this hub is for your legitimate professional or personal use as permitted by the hub operator. You must not redistribute, resell, scrape, or misuse content or the platform in a way that infringes rights or harms other users.</p>
<h3>5. Acceptable use</h3>
<p>You agree not to attempt to disrupt the service, bypass security controls, or use the hub for unlawful or misleading financial promotions. The operator may suspend accounts that breach these terms.</p>
<h3>6. Privacy</h3>
<p>How we process personal data is described in this hub’s separate Privacy Policy (UK GDPR). Please read and acknowledge that policy when prompted. For privacy questions, contact the hub administrator.</p>
<h3>7. Changes and contact</h3>
<p>These terms may be updated from the hub dashboard. Continued use after you accept a new version constitutes agreement to that version. For questions, use the support contact shown on this hub.</p>
HTML;
    }

    public static function whiteLabel(): string
    {
        return <<<'HTML'
<h2>Terms &amp; Conditions — White-labelled Hub</h2>
<p>Welcome to this private white-labelled hub. Access is typically limited to invited advisors and authorised staff. By signing in, you agree to these Terms &amp; Conditions. A hub administrator may edit this content; you may need to accept updates when you next sign in.</p>
<h3>1. Purpose of this hub</h3>
<p>This hub provides firm- or client-specific content, tools, and workflows (which may include catalogue access, compliance pre-approval, website templates, and related dashboards) under the branding and rules set by the hub operator.</p>
<h3>2. Authorised users</h3>
<p>Accounts are provisioned for invited users (for example via import). You must only use credentials issued for you and must not share access with unauthorised people.</p>
<h3>3. Confidentiality</h3>
<p>Materials and data on this hub may be confidential to the firm or its clients. You agree to handle them in line with your professional obligations and any firm policies.</p>
<h3>4. Credits and billing</h3>
<p>Where unlimited or allotted credits, advisor billing, or module fees apply, they follow the commercial arrangements configured for this hub. Unpaid amounts may affect access as configured by the operator.</p>
<h3>5. Compliance workflows</h3>
<p>If social media, website, or general content pre-approval modules are enabled, submissions and approvals must follow the statuses and processes defined on this hub. Approvals do not replace your own regulatory responsibilities.</p>
<h3>6. Acceptable use and security</h3>
<p>Do not misuse the platform, attempt unauthorised access, or upload unlawful or inappropriate material. The hub operator may suspend or discontinue accounts that breach these terms or firm policy.</p>
<h3>7. Privacy</h3>
<p>How personal data is processed on this hub is described in the separate Privacy Policy (UK GDPR). Please read and acknowledge that policy when prompted. Contact your hub administrator for privacy questions.</p>
<h3>8. Changes and contact</h3>
<p>These terms can be updated by users who have permission to manage Terms &amp; Conditions. Contact your hub administrator for questions about access or these terms.</p>
HTML;
    }

    public static function central(): string
    {
        return <<<'HTML'
<h2>Terms &amp; Conditions — Central Hub Controller</h2>
<p>This Central Hub Controller is the platform control plane for managing Shared and White-labelled hubs. By signing in, you agree to these Terms &amp; Conditions. Administrators with the appropriate capability may update this text.</p>
<h3>1. Purpose</h3>
<p>Central Hub is used for hub registry, deploy wiring, Functionalities and Capabilities configuration, platform payment methods, remote control of content hubs, and related Power Admin tools. It is not a member content catalogue unless explicitly enabled.</p>
<h3>2. Authorised operators</h3>
<p>Access is limited to authorised platform operators (for example Power Admin and related control-plane roles). You must use Central only for legitimate platform administration.</p>
<h3>3. Remote control and data</h3>
<p>When acting on a Shared or White-labelled hub, you may view or change that hub’s data according to wiring and Capabilities. Treat remote credentials and tenant data as confidential and use the minimum access required for the task.</p>
<h3>4. Configuration responsibility</h3>
<p>Changes to Functionalities, Capabilities, modules, billing, and hub wiring can affect end users on content hubs. Confirm settings carefully before saving.</p>
<h3>5. Security</h3>
<p>Protect your credentials, enable available security features (such as login OTP when required), and report suspected misuse promptly.</p>
<h3>6. Acceptable use</h3>
<p>Do not use Central to harm tenants, bypass agreed controls, or access hubs without authorisation. Misuse may result in suspension of access.</p>
<h3>7. Privacy</h3>
<p>How personal data is processed on Central Hub is described in the separate Privacy Policy (UK GDPR). Please read and acknowledge that policy when prompted.</p>
<h3>8. Changes and contact</h3>
<p>These terms may be updated from the dashboard by roles with “Manage terms &amp; conditions” enabled. Contact your platform administrator for questions.</p>
HTML;
    }
}
