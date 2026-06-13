<?php

namespace Developertugrul\GarantiPos\Enums;

class TransactionType
{
    const SALE = 'sales';
    const CANCEL = 'void';
    const VOID = 'void';
    const REFUND = 'refund';
    const PRE_AUTH = 'preauth';
    const POST_AUTH = 'postauth';
    const REWARD_INQUIRY = 'rewardinq';
    const REWARD_USAGE = 'sales';
    const ORDER_INQUIRY = 'orderinq';
    const ORDER_HISTORY_INQUIRY = 'orderhistoryinq';
    const ORDER_LIST_INQUIRY = 'orderlistinq';
    const BATCH_INQUIRY = 'batchinq';
    const BIN_INQUIRY = 'bininq';
    const DCC_INQUIRY = 'dccinq';
    const CAMPAIGN_CODE_INQUIRY = 'campaigncodeinq';
    const RECURRING_VOID = 'recurringvoid';
    const RECURRING_UPDATE = 'recurringupdate';
    const IDENTIFY_INQUIRY = 'identifyinq';
    const EXTENDED_CREDIT = 'extendedcredit';
    const EXTENDED_CREDIT_INQUIRY = 'extendedcreditinq';
    const COMMERCIAL_CARD = 'commercialcard';
    const COMMERCIAL_CARD_EXTENDED_CREDIT = 'commercialcardextendedcredit';
    const CEPBANK = 'cepbank';
    const GARANTI_PAY_DATA_REQUEST = 'gpdatarequest';
}
