<?php

declare(strict_types=1);

use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Social HQ reviews every member who is not an admin (ApprovalRules). The
 * rule already applies at runtime; this makes the stored flags say the same,
 * so the members page and pending invites show "needs approval".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! ApprovalRules::membersAlwaysReviewed()) {
            return;
        }

        DB::table('user_workspace')->where('is_admin', false)->update(['requires_approval' => true]);
        DB::table('invites')->whereNull('accepted_at')->where('is_admin', false)->update(['requires_approval' => true]);
    }

    public function down(): void
    {
        // The previous flags are not kept; members stay flagged.
    }
};
