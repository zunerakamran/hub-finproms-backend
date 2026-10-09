<?php

namespace App\Support;

use App\Models\Hub;

/**
 * Default Privacy Policy HTML per hub type (UK GDPR transparency notice).
 * Capable admins can replace this via the dashboard editor.
 * Legal copy should be reviewed by the customer’s counsel before go-live.
 */
class PrivacyPolicyDefaults
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
<h2>Privacy Policy — Shared Hub</h2>
<p>This Privacy Policy explains how personal data is processed when you use this FinProms Shared Hub. It applies under UK GDPR and the Data Protection Act 2018. A hub administrator may update this text; you may be asked to acknowledge the latest version again.</p>
<h3>1. Who is responsible</h3>
<p>The hub operator (as identified on this hub or in your contract) is the data controller for personal data processed on this hub. For questions, contact the hub administrator using the support contact shown on this hub. You may also complain to the Information Commissioner’s Office (ICO) at <a href="https://ico.org.uk" rel="noopener noreferrer" target="_blank">ico.org.uk</a>.</p>
<h3>2. Personal data we process</h3>
<p>Depending on how you use the hub, this may include: name, email address, password (stored as a hash), profile avatar, account role and firm membership, subscription and billing details (including billing name/email and payment references via Stripe where used), support ticket content and attachments, activity and security logs (which may include IP address and browser information), and any content or documents you upload.</p>
<h3>3. Purposes and lawful bases</h3>
<ul>
<li><strong>Account, authentication, and security</strong> — to provide access and protect the service (contract and/or legitimate interests).</li>
<li><strong>Subscriptions, credits, purchases, and invoices</strong> — to fulfil your orders and keep financial records (contract and/or legal obligation).</li>
<li><strong>Support and administration</strong> — to respond to tickets and operate the hub (legitimate interests / contract).</li>
<li><strong>Audit and activity logs</strong> — security, abuse prevention, and operational accountability (legitimate interests).</li>
</ul>
<p>Where we rely on consent (for example non-essential cookies or marketing, if enabled later), you can withdraw it at any time.</p>
<h3>4. Recipients and processors</h3>
<p>Personal data may be processed by service providers acting on our instructions, such as hosting providers, email delivery services, Stripe (payments), and (where configured) file storage. Central Hub may receive backup copies of this hub’s database under the operator’s backup arrangements.</p>
<h3>5. International transfers</h3>
<p>If a provider stores or processes data outside the UK, appropriate safeguards (such as an adequacy decision or standard contractual clauses / UK IDTA) will be used where required.</p>
<h3>6. Retention</h3>
<p>We keep personal data only as long as needed for the purposes above, including legal, accounting, and security requirements. Session and security tokens are short-lived; invoices and some logs may be retained longer. Hub database backups are retained according to the configured backup retention settings.</p>
<h3>7. Your rights</h3>
<p>Under UK GDPR you may have rights to access, rectify, erase, restrict, or object to certain processing, and to data portability. To exercise these rights, contact the hub administrator. We may need to verify your identity. Some records (for example anonymised audit events or invoices) may be retained where we have a continuing legal or legitimate need.</p>
<h3>8. Cookies</h3>
<p>This hub uses essential cookies only (session login and CSRF protection). We do not currently use analytics or marketing cookies. If that changes, we will seek consent where required.</p>
<h3>9. Security</h3>
<p>We use technical and organisational measures appropriate to the risk, including encrypted connections, access controls, hashed passwords, and optional login OTP where enabled on your account.</p>
<h3>10. Changes</h3>
<p>This policy may be updated from the hub dashboard. Continued use after you acknowledge a new version confirms you have been informed of that version.</p>
HTML;
    }

    public static function whiteLabel(): string
    {
        return <<<'HTML'
<h2>Privacy Policy — White-labelled Hub</h2>
<p>This Privacy Policy explains how personal data is processed on this private white-labelled hub under UK GDPR and the Data Protection Act 2018. Access is typically limited to invited advisors and authorised staff. A hub administrator may update this text; you may need to acknowledge updates when you next sign in.</p>
<h3>1. Who is responsible</h3>
<p>The hub operator (usually the firm or client organisation operating this hub, as identified in your invitation or contract) is the data controller for personal data on this hub. Contact your hub administrator for privacy questions. You may also complain to the ICO at <a href="https://ico.org.uk" rel="noopener noreferrer" target="_blank">ico.org.uk</a>.</p>
<h3>2. Personal data we process</h3>
<p>This may include: name, email, password hash, avatar, role and firm membership, module entitlements, billing and invoice details where advisor or module billing applies, compliance workflow submissions and attachments (social media, website, or general content), website template request contact details (phone, email, address) where used, firm documents you upload or access, support tickets, and activity / compliance audit logs (which may include IP address and browser information).</p>
<h3>3. Purposes and lawful bases</h3>
<ul>
<li><strong>Providing hub access and security</strong> — contract and/or legitimate interests.</li>
<li><strong>Firm and advisor administration</strong> — including imports and role assignment (contract / legitimate interests).</li>
<li><strong>Compliance pre-approval workflows</strong> — to operate firm content review processes (legitimate interests / contract; may also support legal or regulatory obligations of the operator).</li>
<li><strong>Billing</strong> — where configured (contract / legal obligation).</li>
<li><strong>Audit trails</strong> — accountability for approvals and security (legitimate interests / legal obligation where applicable).</li>
</ul>
<h3>4. Recipients and processors</h3>
<p>Data may be processed by hosting, email, Stripe (if payments are enabled), file storage, and (where website publishing is enabled) cPanel or similar hosting APIs for live sites. Central Hub may hold encrypted remote-DB credentials and backup copies under the platform operator’s arrangements.</p>
<h3>5. International transfers</h3>
<p>Where providers process data outside the UK, appropriate transfer safeguards will be used where required by law.</p>
<h3>6. Retention</h3>
<p>Data is retained as needed to operate the hub, meet legal/accounting needs, and maintain compliance audit integrity. Historical backups may retain personal data until pruned under backup retention settings. Erasure requests may anonymise identity while preserving necessary audit events.</p>
<h3>7. Your rights</h3>
<p>You may request access, correction, erasure, restriction, objection, or portability where applicable. Contact your hub administrator. Identity checks may apply. Some anonymised or legally required records may be retained.</p>
<h3>8. Cookies</h3>
<p>This hub uses essential cookies only (session login and CSRF protection). Analytics and marketing cookies are not used in the current product.</p>
<h3>9. Security and confidentiality</h3>
<p>Treat hub materials as confidential per your firm policy. Protect your credentials and use available security features (such as login OTP). Report suspected misuse promptly.</p>
<h3>10. Changes</h3>
<p>This policy can be updated by users with permission to manage the Privacy Policy. Acknowledgement may be required after updates.</p>
HTML;
    }

    public static function central(): string
    {
        return <<<'HTML'
<h2>Privacy Policy — Central Hub Controller</h2>
<p>This Privacy Policy explains how personal data is processed on the FinProms Central Hub Controller under UK GDPR and the Data Protection Act 2018. Central Hub is the platform control plane for Shared and White-labelled hubs. Administrators with the appropriate capability may update this text.</p>
<h3>1. Who is responsible</h3>
<p>The platform operator is the data controller for personal data processed on Central Hub (operator accounts, hub registry metadata, and control-plane tooling). Contact your platform administrator for privacy questions. Complaints may be made to the ICO at <a href="https://ico.org.uk" rel="noopener noreferrer" target="_blank">ico.org.uk</a>.</p>
<h3>2. Personal data we process</h3>
<p>This may include: operator name and email, password hash, avatar, role and capabilities, activity and security logs (IP address / user agent), platform billing settings, and hub registry / deploy wiring (including encrypted remote database credentials). When acting on a content hub, operators may view or change that hub’s personal data according to wiring and Capabilities — that processing is governed by that hub’s Privacy Policy and the operator’s instructions.</p>
<h3>3. Purposes and lawful bases</h3>
<ul>
<li><strong>Platform administration and remote control</strong> — legitimate interests / contract.</li>
<li><strong>Security and access control</strong> — legitimate interests.</li>
<li><strong>Hub backups stored on Central</strong> — legitimate interests / contract (supporting tenant continuity); backups may contain personal data from content hubs.</li>
</ul>
<h3>4. Recipients and processors</h3>
<p>Hosting, email delivery, and (where used) Stripe for platform payments. Backup storage on Central holds copies received from content hubs under configured retention.</p>
<h3>5. International transfers</h3>
<p>Where providers process data outside the UK, appropriate safeguards will be used where required.</p>
<h3>6. Retention</h3>
<p>Operator account and log data are retained as needed for security and administration. Hub backups follow configured retention counts. Remote credentials are stored encrypted and should be kept only while a hub remains registered.</p>
<h3>7. Your rights</h3>
<p>Operators may request access, correction, erasure, restriction, objection, or portability where applicable via the platform administrator. Some security and backup records may be retained or anonymised rather than fully deleted.</p>
<h3>8. Cookies</h3>
<p>Central Hub uses essential cookies only (session login and CSRF protection). No marketing or analytics cookies are set by the current product.</p>
<h3>9. Security</h3>
<p>Protect credentials, enable login OTP where appropriate, and use the minimum access required when acting on tenant hubs. Treat remote credentials and tenant data as confidential.</p>
<h3>10. Changes</h3>
<p>This policy may be updated from the dashboard by roles with “Manage privacy policy” enabled.</p>
HTML;
    }
}
