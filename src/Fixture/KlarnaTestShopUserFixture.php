<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use function Safe\preg_replace;
use function Safe\sprintf;
use Sylius\Bundle\CoreBundle\Fixture\Factory\ExampleFactoryInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class KlarnaTestShopUserFixture extends AbstractFixture
{
    /** @var ExampleFactoryInterface */
    protected $shopUserExampleFactory;

    /** @var ExampleFactoryInterface */
    protected $addressExampleFactory;

    /** @var EntityManagerInterface */
    protected $shopUserManager;

    /** @var \Faker\Generator */
    private $faker;

    public function __construct(
        ExampleFactoryInterface $shopUserExampleFactory,
        ExampleFactoryInterface $addressExampleFactory,
        EntityManagerInterface $shopUserManager
    ) {
        $this->shopUserExampleFactory = $shopUserExampleFactory;
        $this->addressExampleFactory = $addressExampleFactory;
        $this->shopUserManager = $shopUserManager;

        $this->faker = \Faker\Factory::create();
    }

    public function load(array $options): void
    {
        for ($i = 0; $i < $options['amount']; ++$i) {
            $testData = $this->getKlarnaTestDataByCountry($options['country'], $options);

            /** @var ShopUserInterface $shopUser */
            $shopUser = $this->shopUserExampleFactory->create([
                'password' => $options['password'],
                'enabled' => true,
            ] + $this->getOptions($testData, ['email', 'first_name', 'last_name', 'phone_number', 'gender', 'birthday']));

            /** @var AddressInterface $address */
            $address = $this->addressExampleFactory->create([
                'country_code' => $options['country'],
            ] + $this->getOptions($testData, ['first_name', 'last_name', 'phone_number', 'postcode', 'city', 'street']));

            /** @var CustomerInterface $customer */
            $customer = $shopUser->getCustomer();
            $customer->addAddress($address);

            $this->shopUserManager->persist($shopUser);

            if (0 === ($i % 50)) {
                $this->shopUserManager->flush();
            }
        }

        $this->shopUserManager->flush();
    }

    public function getName(): string
    {
        return 'setono_quickpay_klarna_test_shop_user';
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->integerNode('amount')->isRequired()->min(0)->end()

                ->enumNode('country')->values(self::getSupportingCountries())->end()

                ->scalarNode('password')->defaultValue('klarna')->end()

                ->booleanNode('approved')->defaultTrue()->end()
            ->end()
        ;
    }

    protected static function getSupportingCountries(): array
    {
        return [
            'SE',
            'AT',
            'FI',
            'DK',
            'DE',
            'NO',
            'NL',
            'CH',
        ];
    }

    /**
     * @see https://developers.klarna.com/documentation/testing-environment/sample-data/
     */
    protected function getKlarnaTestDataByCountry(string $countryCode, array $options): array
    {
        $klarnaDefaultTestData = [
            'first_name' => sprintf('Testperson-%s', strtolower($countryCode)),
            'email' => $options['approved'] ? $this->faker->email : preg_replace('@', '+denied@', $this->faker->email),
            'last_name' => $options['approved'] ? 'Approved' : 'Denied',
        ];

        $klarnaCountryDependantTestData = [
            'SE' => [
                'phone_number' => '0765260000',
                'street' => 'Stårgatan 1',
                'city' => 'Ankeborg',
                'postcode' => '12345',
            ],
            'AT' => [
                'phone_number' => $options['approved'] ? '0676 2600000' : '0676 2800000',
                'street' => sprintf('Klarna-Straße %s', $this->faker->randomElement([1, 2, 3])),
                'city' => 'Hausmannstätten',
                'postcode' => $options['approved'] ? '8071' : '8070',
                'gender' => $options['approved'] ? CustomerInterface::MALE_GENDER : CustomerInterface::FEMALE_GENDER,
                'birthday' => $options['approved'] ? '1960-04-14' : '1980-04-14',
            ],
            'FI' => [
                'phone_number' => '0401234567',
                'street' => 'Kiväärikatu 10',
                'city' => 'Pori',
                'postcode' => '28100',
            ],
            'DK' => [
                'phone_number' => '20 123 456',
                'street' => 'Sæffleberggate 56,1 mf',
                'city' => 'Varde',
                'postcode' => '6800',
            ],
            'DE' => [
                'phone_number' => '01522113356',
                'street' => 'Hellersbergstraße 14',
                'city' => 'Neuss',
                'postcode' => '41460',
                'gender' => CustomerInterface::MALE_GENDER,
                'birthday' => '1960-07-07',
            ],
            'NO' => [
                'phone_number' => '40 123 456',
                'street' => 'Sæffleberggate 56',
                'city' => 'Oslo',
                'postcode' => '0563',
            ],
            'NL' => [
                'phone_number' => '0612345678',
                'street' => 'Neherkade 1 XI',
                'city' => 'Gravenhage',
                'postcode' => '2521VA',
                'gender' => CustomerInterface::MALE_GENDER,
                'birthday' => '1960-07-10',
            ],
            'CH' => [
                'first_name' => $options['approved'] ? $this->faker->firstName : 'test',
                'last_name' => $options['approved'] ? $this->faker->lastName : 'test',
                'phone_number' => '012345678',
                'street' => $options['approved'] ? 'Bahnhofstrasse 77' : 'teststreet 77',
                'city' => 'Zürich',
                'postcode' => '8001',
                'gender' => CustomerInterface::MALE_GENDER,
                'birthday' => '1960-01-01',
            ],
        ];

        return $klarnaDefaultTestData + $klarnaCountryDependantTestData[$countryCode];
    }

    protected function getOptions(array $testData, array $requiredKeys): array
    {
        foreach ($testData as $key => $value) {
            if (!in_array($key, $requiredKeys, true)) {
                unset($testData[$key]);
            }
        }

        return $testData;
    }
}
