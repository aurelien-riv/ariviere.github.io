---
layout: default
title:  "Magento2: If your super attributes has loads of values, that patch may speed up your website!"
date:   2020-03-18 08:00:00 +0200
categories: [magento2]
tags: [performance, module-swatch]
description: When you load the product page of a configurable, Magento fetches all of its values. That patch makes it load only what it needs, saving time and memory. 
excerpt: When you load the product page of a configurable, Magento fetches all of its values. That patch makes it load only what it needs, saving time and memory. 
---

# Optimize configurable-product page that varyies on heavy super attributes

## The issue with Magento2's module swatch

When you open the product page of a configurable product, Magento will load the configurable itself, but also its variants and the attributes it varies on.

Most of the time, you won't notice any performance impact as your super attributes holds maybe tens of values. 
But when it holds thousands, or ten of thousands of values, it may cost a few seconds (and a few memory) to gather these values.
When loaded, Magento will then build a new array with the identifiers of the values the product really needs.

In my case, my super attribute contained more than 15,000 values, so I had to make something to fix that.

## Let change that silly behaviour

Here's my plugin around _\Magento\Swatches\Helper\Data::getSwatchAttributesAsArray_, which completely replaces the default implementation.

I strongly recommend you to have a look to the original implementation, and to it caller, 
_\Magento\Swatches\Block\Product\Renderer\Configurable::getJsonSwatchConfig_, to understand what it does.

**di.xml**

{% highlight xml %}
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="\Magento\Swatches\Helper\Data">
        <plugin name="ari_performance_product_plugin_fast_swatch" type="\Ari\Performance\Plugin\SwatchHelperData" sortOrder="1"/>
    </type>
</config>
{% endhighlight %}

**Plugin/SwatchHelperData.php**

{% highlight php %}
<?php

namespace Ari\Performance\Plugin;

use Magento\Catalog\Api\Data\ProductInterface as Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\ConfigurableProduct\Block\Product\View\Type\Configurable as ConfigurableBlock;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Store\Model\StoreManager;
use Magento\Swatches\Helper\Data as Subject;
use Magento\Swatches\Model\SwatchAttributesProvider;

class SwatchHelperData
{
    private $configurableBlock;
    private $swatchAttributesProvider;
    private $storeManager;

    public function __construct(ConfigurableBlock $configurableBlock, SwatchAttributesProvider $swatchAttributesProvider, StoreManager $storeManager)
    {
        $this->configurableBlock = $configurableBlock;
        $this->swatchAttributesProvider = $swatchAttributesProvider;
        $this->storeManager = $storeManager;
    }

    public function aroundGetSwatchAttributesAsArray(Subject $subject, callable $proceed, Product $product) : array
    {
        /** @var Attribute[] $swatchAttributes */
        $swatchAttributes = $this->swatchAttributesProvider->provide($product);

        $result = [];
        foreach ($swatchAttributes as $swatchAttribute) {
            $swatchAttribute->setStoreId($this->storeManager->getStore()->getId());
            $attributeData = $swatchAttribute->getData();
            foreach ($this->getOptions($product, $swatchAttribute) as $option) {
                $attributeData['options'][$option['value']] = $option['label'];
            }
            $result[$attributeData['attribute_id']] = $attributeData;
        }

        return $result;
    }

    private function getOptions(Product $product, Attribute $swatchAttribute)
    {
        $source = $swatchAttribute->getSource();
        if ($source instanceof Table) {
            $productOptionIds = $this->getProductOptionIds($product, $swatchAttribute);
            return $source->getSpecificOptions($productOptionIds, false);
        }
        return $source->getAllOptions(false);
    }

    private function getProductOptionIds(Product $product, Attribute $swatchAttribute) : array
    {
        $ids = [];
        foreach ($this->configurableBlock->getAllowProducts() as $product) {
            $ids[] = $product->getData($swatchAttribute->getAttributeCode());
        }
        return $ids;
    }
}
{% endhighlight %}
