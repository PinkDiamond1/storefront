<?php declare(strict_types=1);

namespace Shopware\Storefront\Page\Checkout\Cart;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoader;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class CheckoutCartPageLoader
{
    /**
     * @var GenericPageLoader
     */
    private $genericLoader;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentMethodRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $shippingMethodRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    public function __construct(
        GenericPageLoader $genericLoader,
        EventDispatcherInterface $eventDispatcher,
        CartService $cartService,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $shippingMethodRepository,
        EntityRepositoryInterface $salesChannelRepository
    ) {
        $this->genericLoader = $genericLoader;
        $this->eventDispatcher = $eventDispatcher;
        $this->cartService = $cartService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    public function load(Request $request, SalesChannelContext $context)
    {
        $page = $this->genericLoader->load($request, $context);
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $context->getSalesChannel();
        $this->updateSalesChannel($salesChannel, $context);
        $page = CheckoutCartPage::createFrom($page);
        //todo load shipping countries to select from
        $page->setShippingCountries($salesChannel->getCountries());
        //todo load payment methods to select from
        $page->setPaymentMethods($this->getPaymentMethods($context));
        //todo load dispatch methods to select from
        $page->setShippingMethods($this->getShippingMethods($context));
        $page->setCart($this->cartService->getCart($context->getToken(), $context));

        $this->eventDispatcher->dispatch(
            CheckoutCartPageLoadedEvent::NAME,
            new CheckoutCartPageLoadedEvent($page, $context, $request)
        );

        return $page;
    }

    private function getPaymentMethods(SalesChannelContext $context): PaymentMethodCollection
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('active', true));
        /** @var PaymentMethodCollection $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository->search($criteria, $context->getContext())->getEntities();

        return $paymentMethods->filterByActiveRules($context);
    }

    private function getShippingMethods(SalesChannelContext $context): ShippingMethodCollection
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('active', true));
        /** @var ShippingMethodCollection $shippingMethods */
        $shippingMethods = $this->shippingMethodRepository->search($criteria, $context->getContext())->getEntities();

        return $shippingMethods->filterByActiveRules($context);
    }

    private function updateSalesChannel(SalesChannelEntity $salesChannelEntity, SalesChannelContext $context): void
    {
        /** @var SalesChannelEntity $updateData */
        $updateData = $this->salesChannelRepository
            ->search((new Criteria([$salesChannelEntity->getId()]))
                ->addAssociation('countries')
                ->addAssociation('paymentMethods')
                ->addAssociation('shippingMethods'), $context->getContext())
            ->get($salesChannelEntity->getId());

        if (($countries = $updateData->getCountries()) instanceof CountryCollection) {
            $salesChannelEntity->setCountries($countries);
        }
        if (($paymentMethods = $updateData->getPaymentMethods()) instanceof PaymentMethodCollection) {
            $salesChannelEntity->setPaymentMethods($paymentMethods);
        }
        if (($shippingMethods = $updateData->getShippingMethods()) instanceof ShippingMethodCollection) {
            $salesChannelEntity->setShippingMethods($shippingMethods);
        }
    }
}
