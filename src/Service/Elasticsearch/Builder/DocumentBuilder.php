<?php
/**
 * Copyright (c) 2016-2017 Invertus, JSC
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Invertus\Brad\Service\Elasticsearch\Builder;

use Attribute;
use Category;
use Core_Business_ConfigurationInterface;
use Core_Foundation_Database_EntityManager;
use Feature;
use FeatureValue;
use Link;
use Manufacturer;
use Product;
use Shop;
use StockAvailable;

/**
 * Class DocumentBuilder
 *
 * @package Invertus\Brad\Service\Elasticsearch\Builder
 */
class DocumentBuilder
{
    /**
     * @var array
     */
    protected static $groupsIds;

    /**
     * @var array
     */
    protected static $countriesIds;

    /**
     * @var array
     */
    protected static $currenciesIds;

    /**
     * @var Link
     */
    private $link;

    /**
     * @var Shop
     */
    private $shop;

    /**
     * @var Core_Foundation_Database_EntityManager
     */
    private $em;

    /**
     * @var Core_Business_ConfigurationInterface
     */
    private $configuration;

    /**
     * DocumentBuilder constructor.
     *
     * @param Link $link
     * @param Shop $shop
     * @param Core_Foundation_Database_EntityManager $em
     * @param Core_Business_ConfigurationInterface $configuration
     */
    public function __construct(Link $link, Shop $shop, Core_Foundation_Database_EntityManager $em, Core_Business_ConfigurationInterface $configuration)
    {
        $this->link = $link;
        $this->shop = $shop;
        $this->em = $em;
        $this->configuration = $configuration;

        $this->initPricesData();
    }

    /**
     * Build product fields for indexing
     *
     * @param Product $product
     *
     * @return array
     */
    public function buildProductBody(Product $product)
    {
        $body = [];
        $body['id_product'] = $product->id;
        $body['id_supplier'] = $product->id_supplier;
        $body['id_manufacturer'] = $product->id_manufacturer;
        $body['manufacturer_name'] = Manufacturer::getNameById($product->id_manufacturer);
        $body['id_category_default'] = $product->id_category_default;
        $body['on_sale'] = $product->on_sale;
        $body['ean13'] = $product->ean13;
        $body['reference'] = $product->reference;
        $body['upc'] = $product->upc;
        $body['price'] = $product->price;
        $body['show_price'] = $product->show_price;
        $body['quantity'] = $product->quantity;
        $body['customizable'] = $product->customizable;
        $body['minimal_quantity'] = $product->minimal_quantity;
        $body['available_for_order'] = $product->available_for_order;
        $body['condition'] = $product->condition;
        $body['weight'] = $product->weight;
        $body['out_of_stock'] = $product->out_of_stock;
        $body['is_virtual'] = $product->is_virtual;
        $body['on_sale'] = $product->on_sale;
        $body['id_image'] = Product::getCover($product->id)['id_image'];
        $body['id_combination_default'] = $product->getDefaultIdProductAttribute();
        $body['categories'] = $product->getCategories();
        $body['total_quantity'] = StockAvailable::getQuantityAvailableByProduct($product->id);

        $defaultCategory = new Category($product->id_category_default);

        foreach ($product->name as $idLang => $name) {
            $body['name_lang_'.$idLang] = $name;
            $body['description_lang_'.$idLang] = $product->description[$idLang];
            $body['short_description_lang_'.$idLang] = $product->description_short[$idLang];
            $body['link_rewrite_lang_'.$idLang] = $product->link_rewrite[$idLang];
            $body['link_lang_'.$idLang] = $this->link->getProductLink($product, $product->link_rewrite[$idLang]);
            $body['default_category_name_lang_'.$idLang] = $defaultCategory->name[$idLang];
        }

        $features = $product->getFeatures();
        $attributes = Product::getAttributesInformationsByProduct($product->id);

        if ($features) {
            foreach ($features as $feature) {
                $featureObj = new Feature($feature['id_feature']);
                $featureValueObj = new FeatureValue($feature['id_feature_value']);

                foreach ($featureObj->name as $idLang => $name) {
                    $body['feature_'.$featureObj->id.'_lang_'.$idLang] = $name;
                    $body['feature_value_'.$featureValueObj->id.'_lang_'.$idLang] = $featureValueObj->value[$idLang];
                }

                $body['feature_'.$featureObj->id] = $featureValueObj->id;
            }
        }

        if ($attributes) {
            foreach ($attributes as $attribute) {
                $attributeObj = new Attribute($attribute['id_attribute']);

                foreach ($attributeObj->name as $idLang => $name) {
                    $body['attribute_' . $attributeObj->id . '_lang_' . $idLang] = $name;
                }

                $body['attribute_group_' . $attribute['id_attribute_group']][] = $attributeObj->id;
            }
        }

        return $body;
    }

    /**
     * Build product prices body
     *
     * @param Product $product
     * @param int $idShop
     *
     * @return array
     */
    public function buildProductPriceBody(Product $product, $idShop)
    {
        $useTax = (bool) $this->configuration->get('PS_TAX');

        $body = [];

        foreach (self::$groupsIds as $idGroup) {
            foreach (self::$countriesIds as $idCountry) {
                foreach (self::$currenciesIds as $idCurrency) {

                    $price = Product::priceCalculation(
                        $idShop,
                        $product->id,
                        null,
                        $idCountry,
                        null,
                        null,
                        $idCurrency,
                        $idGroup,
                        $product->minimal_quantity,
                        $useTax,
                        6,
                        false,
                        true,
                        true,
                        $pr,
                        true
                    );

                    $body['price_group_'.$idGroup.'_country_'.$idCountry.'_currency_'.$idCurrency] = $price;
                }
            }
        }

        return $body;
    }

    /**
     * Initialize groups, countries & currencies ids.
     * These are used for calculating prices.
     */
    private function initPricesData()
    {
        $idShop = (int) $this->shop->id;

        self::$countriesIds = $this->em->getRepository('BradCountry')->findAllIdsByShopId($idShop);
        self::$currenciesIds = $this->em->getRepository('BradCurrency')->findAllIdsByShopId($idShop);
        self::$groupsIds = $this->em->getRepository('BradGroup')->findAllIdsByShopId($idShop);
    }
}
