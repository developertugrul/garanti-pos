<?php

namespace Developertugrul\GarantiPos\Enums;

class TransactionType
{
    const SALE = 'sales';
    const CANCEL = 'cancel';
    const REFUND = 'refund';
    const PRE_AUTH = 'preauth';
    const POST_AUTH = 'postauth';
    const REWARD_INQUIRY = 'rewardinq';
    const REWARD_USAGE = 'rewardusage';
    const ORDER_INQUIRY = 'orderinq';
}
