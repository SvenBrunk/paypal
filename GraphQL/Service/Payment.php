<?php
/**
 * This file is part of OXID eSales PayPal module.
 *
 * OXID eSales PayPal module is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OXID eSales PayPal module is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OXID eSales PayPal module.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @link      http://www.oxid-esales.com
 * @copyright (C) OXID eSales AG 2003-2018
 */

declare(strict_types=1);

namespace OxidEsales\PayPalModule\GraphQL\Service;

use OxidEsales\Eshop\Core\Model\BaseModel;
use OxidEsales\GraphQL\Storefront\Basket\DataType\Basket as BasketDataType;
use OxidEsales\GraphQL\Storefront\Shared\Infrastructure\Basket as SharedBasketInfrastructure;
use OxidEsales\GraphQL\Storefront\Basket\Service\BasketRelationService;
use OxidEsales\PayPalModule\GraphQL\DataType\PayPalCommunicationInformation;
use OxidEsales\PayPalModule\GraphQL\DataType\PayPalTokenStatus;
use OxidEsales\PayPalModule\GraphQL\Exception\BasketValidation;
use OxidEsales\PayPalModule\GraphQL\Exception\GraphQLServiceNotFound;
use OxidEsales\PayPalModule\GraphQL\Infrastructure\Request as RequestInfrastructure;
use OxidEsales\PayPalModule\Model\Response\ResponseGetExpressCheckoutDetails;
use OxidEsales\Eshop\Application\Model\Basket as EshopBasketModel;
use OxidEsales\Eshop\Application\Model\User as EshopUserModel;
use OxidEsales\Eshop\Application\Model\Address as EshopAddressModel;

final class Payment
{
    /** @var RequestInfrastructure */
    private $requestInfrastructure;

    /** @var SharedBasketInfrastructure */
    private $sharedBasketInfrastructure;

    /** @var BasketRelationService */
    private $basketRelationService;

    public function __construct(
        RequestInfrastructure $requestInfrastructure,
        SharedBasketInfrastructure $sharedBasketInfrastructure = null,
        BasketRelationService $basketRelationService = null
    ) {
        $this->requestInfrastructure = $requestInfrastructure;
        $this->sharedBasketInfrastructure = $sharedBasketInfrastructure;
        $this->basketRelationService = $basketRelationService;
    }

    public function getSharedBasketInfrastructure(): SharedBasketInfrastructure
    {
        if (is_null($this->sharedBasketInfrastructure)) {
            throw GraphQLServiceNotFound::byServiceName(SharedBasketInfrastructure::class);
        }

        return $this->sharedBasketInfrastructure;
    }

    public function getBasketRelationService(): BasketRelationService
    {
        if (is_null($this->basketRelationService)) {
            throw GraphQLServiceNotFound::byServiceName(BasketRelationService::class);
        }

        return $this->basketRelationService;
    }

    public function getPayPalTokenStatus(
        string $token,
        ResponseGetExpressCheckoutDetails $details = null
    ): PayPalTokenStatus {
        //NOTE: only when the approval was finished on PayPal site
        //(payment channel and delivery adress registered with PayPal)
        //the getExpressCHeckoutResponse will contain the PayerId. So we can use this to get information
        //about token status. If anything is amiss, PayPal will no let the order pass.

        if (is_null($details)) {
            $details = $this->getExpressCheckoutDetails($token);
        }

        $payerId = $details->getPayerId();

        return new PayPalTokenStatus(
            $token,
            $payerId ? true : false,
            $payerId
        );
    }

    public function getExpressCheckoutDetails(string $token): ResponseGetExpressCheckoutDetails
    {
        $paymentManager = $this->requestInfrastructure->getPaymentManager();
        return $paymentManager->getExpressCheckoutDetails($token);
    }

    /**
     * @throws BasketValidation
     */
    public function getValidEshopBasketModel(
        BasketDataType $userBasket,
        ResponseGetExpressCheckoutDetails $expressCheckoutDetails
    ): EshopBasketModel {
        $sessionBasket = $this->getUserBasketSession($userBasket);

        $this->validateApprovedBasketAmount($sessionBasket, $expressCheckoutDetails, $userBasket);
        $this->validateApprovedBasketAddress($sessionBasket, $expressCheckoutDetails, $userBasket);

        return $sessionBasket;
    }

    private function getUserBasketSession(BasketDataType $userBasket): EshopBasketModel
    {
        return $this->sharedBasketInfrastructure->getCalculatedBasket($userBasket);
    }

    private function validateApprovedBasketAddress(
        EshopBasketModel $sessionBasket,
        ResponseGetExpressCheckoutDetails $expressCheckoutDetails,
        BasketDataType $userBasket
    ): void {
        $modelWithAddress = $this->calculateDeliveryAddressModel($sessionBasket, $userBasket);

        //Ensure delivery address registered with PayPal is the same as shop will use
        $paypalAddressModel = oxNew(EshopAddressModel::class);
        $paypalAddressData = $paypalAddressModel->prepareDataPayPalAddress($expressCheckoutDetails);

        $compareWith = [];
        foreach ($paypalAddressData as $key => $value) {
            $compareWith[$key] = $modelWithAddress->getFieldData($key);
        }

        $diff = array_diff($paypalAddressData, $compareWith);
        if (!empty($diff)) {
            throw BasketValidation::basketAddressChange($userBasket->id()->val());
        }
    }

    /**
     * Delivery address is currently related to user basket
     * if that one is null, the user's invoice address is used as delivery address
     */
    private function calculateDeliveryAddressModel(
        EshopBasketModel $sessionBasket,
        BasketDataType $userBasket
    ): BaseModel {
        $shipToAddress = $this->basketRelationService->deliveryAddress($userBasket);
        if (!is_null($shipToAddress)) {
            /** @var EshopAddressModel $eshopModel */
            $modelWithAddress = $shipToAddress->getEshopModel();
        } else {
            /** @var EshopUserModel $modelWithAddress */
            $modelWithAddress = $sessionBasket->getUser();
        }

        return $modelWithAddress;
    }

    private function validateApprovedBasketAmount(
        EshopBasketModel $sessionBasket,
        ResponseGetExpressCheckoutDetails $expressCheckoutDetails,
        BasketDataType $userBasket
    ): void {
        $paymentManager = $this->requestInfrastructure->getPaymentManager();

        /** @var \OxidEsales\Eshop\Core\Price $price */
        $price = $sessionBasket->getPrice();
        if (
            !$price ||
            !$paymentManager->validateApprovedBasketAmount(
                $price->getBruttoPrice(),
                $expressCheckoutDetails->getAmount()
            )
        ) {
            throw BasketValidation::basketChange($userBasket->id()->val());
        }
    }

    public function getPayPalCommunicationInformation(
        BasketDataType $basket,
        string $returnUrl,
        string $cancelUrl,
        bool $displayBasketInPayPal
    ): PayPalCommunicationInformation {
        $paymentManager = $this->requestInfrastructure->getPaymentManager();
        $standardPaypalController = $this->requestInfrastructure->getStandardDispatcher();

        $shipToAddress = $this->getBasketRelationService()->deliveryAddress($basket);
        $shipToAddressid = $shipToAddress ? (string) $shipToAddress->id(): '';

        $response = $paymentManager->setExpressCheckout(
            $this->getSharedBasketInfrastructure()->getCalculatedBasket($basket),
            $standardPaypalController->getUser(),
            $returnUrl,
            $cancelUrl,
            $displayBasketInPayPal,
            $shipToAddressid
        );

        $token = (string) $response->getToken();

        return new PayPalCommunicationInformation(
            $token,
            $this->getPayPalCommunicationUrl($token)
        );
    }

    public function getPayPalCommunicationUrl($token): string
    {
        $payPalConfig = $this->requestInfrastructure->getPayPalConfig();
        return $payPalConfig->getPayPalCommunicationUrl($token);
    }
}