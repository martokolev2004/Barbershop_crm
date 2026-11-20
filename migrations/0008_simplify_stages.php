<?php
// 14 statuses → 7 stages the owner works with. Old values are mapped; nothing is deleted.
return [
    'up' => [
        "UPDATE businesses SET status = 'qualified' WHERE status IN ('researched','demo_generating','demo_awaiting_approval','demo_approved','ready_for_outreach')",
        "UPDATE businesses SET status = 'replied' WHERE status = 'negotiation'",
    ],
    // Mapping is not reversible (several old values became one); rolling back leaves the stages as they are.
    'down' => [],
];
