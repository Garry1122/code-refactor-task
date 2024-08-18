<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     *
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = $this->getRequestData('data');
        $resellerId = (int)$data['resellerId'];
        $notificationType = (int)$data['notificationType'];

        $result = $this->initializeResult();

        $this->validateInputs($resellerId, $notificationType);

        $reseller = $this->getReseller($resellerId);
        $client = $this->getClient($data, $resellerId);
        $creator = $this->getEmployee($data['creatorId']);
        $expert = $this->getEmployee($data['expertId']);

        $differences = $this->getDifferencesMessage($notificationType, $data, $resellerId);

        $templateData = $this->prepareTemplateData($data, $client, $creator, $expert, $differences);

        $this->validateTemplateData($templateData);

        $this->sendEmployeeNotification($resellerId, $templateData, $result);
        $this->sendClientNotification($notificationType, $data, $resellerId, $client, $templateData, $result);

        return $result;
    }

    private function getRequestData(string $param): array
    {
        return (array)$this->getRequest($param);
    }

    private function initializeResult(): array
    {
        return [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];
    }

    private function validateInputs(int $resellerId, int $notificationType): void
    {
        if ($resellerId === 0) {
            throw new \Exception('Empty resellerId', 400);
        }

        if ($notificationType === 0) {
            throw new \Exception('Empty notificationType', 400);
        }
    }

    private function getReseller(int $resellerId): Seller
    {
        $reseller = Seller::getById($resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }
        return $reseller;
    }

    private function getClient(array $data, int $resellerId): Contractor
    {
        $client = Contractor::getById((int)$data['clientId']);
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('Client not found!', 400);
        }
        return $client;
    }

    private function getEmployee(int $employeeId): Employee
    {
        $employee = Employee::getById($employeeId);
        if ($employee === null) {
            throw new \Exception('Employee not found!', 400);
        }
        return $employee;
    }

    private function getDifferencesMessage(int $notificationType, array $data, int $resellerId): string
    {
        if ($notificationType === self::TYPE_NEW) {
            return __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO' => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }
        return '';
    }

    private function prepareTemplateData(array $data, Contractor $client, Employee $creator, Employee $expert, string $differences): array
    {
        return [
            'COMPLAINT_ID' => (int)$data['complaintId'],
            'COMPLAINT_NUMBER' => (string)$data['complaintNumber'],
            'CREATOR_ID' => (int)$data['creatorId'],
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_ID' => (int)$data['expertId'],
            'EXPERT_NAME' => $expert->getFullName(),
            'CLIENT_ID' => (int)$data['clientId'],
            'CLIENT_NAME' => $client->getFullName() ?: $client->name,
            'CONSUMPTION_ID' => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string)$data['agreementNumber'],
            'DATE' => (string)$data['date'],
            'DIFFERENCES' => $differences,
        ];
    }

    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $value) {
            if (empty($value)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    private function sendEmployeeNotification(int $resellerId, array $templateData, array &$result): void
    {
        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if (!empty($emailFrom) && !empty($emails)) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }
    }

    private function sendClientNotification(int $notificationType, array $data, int $resellerId, Contractor $client, array $templateData, array &$result): void
    {
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            $this->sendClientEmail($resellerId, $client, $templateData, $result);
            $this->sendClientSms($resellerId, $client, $templateData, $result, (int)$data['differences']['to']);
        }
    }

    private function sendClientEmail(int $resellerId, Contractor $client, array $templateData, array &$result): void
    {
        $emailFrom = getResellerEmailFrom($resellerId);
        if (!empty($emailFrom) && !empty($client->email)) {
            MessagesClient::sendMessage([
                [
                    'emailFrom' => $emailFrom,
                    'emailTo' => $client->email,
                    'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                    'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                ],
            ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$templateData['differences']['to']);
            $result['notificationClientByEmail'] = true;
        }
    }

    private function sendClientSms(int $resellerId, Contractor $client, array $templateData, array &$result, int $statusId): void
    {
        if (!empty($client->mobile)) {
            $error = '';
            $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $statusId, $templateData, $error);
            if ($res) {
                $result['notificationClientBySms']['isSent'] = true;
            }
            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        }
    }
}