<?php

namespace Dynamic\Foxy\SingleSignOn\Extension;

use Dynamic\Foxy\API\Client\APIClient;
use Dynamic\Foxy\SingleSignOn\Client\CustomerClient;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Security\Member;

/**
 * Adds Foxy sync actions to Member edit forms in SecurityAdmin.
 */
class MemberItemRequestExtension extends Extension
{
    private static array $allowed_actions = [
        'syncFromFoxy',
        'syncToFoxy',
    ];

    /**
     * Add sync buttons to Member edit form.
     */
    public function updateItemEditForm(Form $form): void
    {
        $record = $this->owner->getRecord();

        if (!$record instanceof Member) {
            return;
        }

        if (!APIClient::is_valid()) {
            return;
        }

        $actions = $form->Actions();
        $actions->push(
            FormAction::create('syncFromFoxy', 'Sync From Foxy')
                ->setUseButtonTag(true)
                ->addExtraClass('btn-outline-secondary')
        );
        $actions->push(
            FormAction::create('syncToFoxy', 'Push To Foxy')
                ->setUseButtonTag(true)
                ->addExtraClass('btn-outline-primary')
        );
    }

    /**
     * Pull customer data from Foxy and update the Member.
     */
    public function syncFromFoxy(): HTTPResponse
    {
        $member = $this->owner->getRecord();

        if (!$member instanceof Member || !$member->Customer_ID) {
            return $this->owner->httpError(400, 'Member not found or not linked to Foxy');
        }

        if (!APIClient::is_valid()) {
            return $this->owner->httpError(500, 'Foxy API not configured');
        }

        $client = CustomerClient::create($member);
        $data = $client->fetchCustomer();

        if (empty($data) || isset($data['error_message'])) {
            $errorMsg = $data['error_message'] ?? 'Failed to fetch customer from Foxy';
            return $this->owner->httpError(500, $errorMsg);
        }

        // Map Foxy fields back to Member
        $fieldMap = [
            'first_name' => 'FirstName',
            'last_name' => 'Surname',
            'email' => 'Email',
        ];

        foreach ($fieldMap as $foxyField => $memberField) {
            if (isset($data[$foxyField])) {
                $member->{$memberField} = $data[$foxyField];
            }
        }

        // Prevent push-back to Foxy
        $member->FromDataFeed = true;
        $member->write();

        return $this->owner->redirectBack();
    }

    /**
     * Push Member data to Foxy.
     */
    public function syncToFoxy(): HTTPResponse
    {
        $member = $this->owner->getRecord();

        if (!$member instanceof Member) {
            return $this->owner->httpError(400, 'Member not found');
        }

        if (!APIClient::is_valid()) {
            return $this->owner->httpError(500, 'Foxy API not configured');
        }

        $client = CustomerClient::create($member);
        $data = $client->putCustomer();

        if (empty($data) || isset($data['error_message'])) {
            $errorMsg = $data['error_message'] ?? 'Failed to sync to Foxy';
            return $this->owner->httpError(500, $errorMsg);
        }

        // Store Customer_ID if this was a new customer
        if (!$member->Customer_ID && isset($data['_links']['self']['href'])) {
            $parts = explode('/', $data['_links']['self']['href']);
            $customerId = end($parts);
            if (is_numeric($customerId)) {
                $member->FromDataFeed = true;
                $member->Customer_ID = (int) $customerId;
                $member->write();
            }
        }

        return $this->owner->redirectBack();
    }
}
