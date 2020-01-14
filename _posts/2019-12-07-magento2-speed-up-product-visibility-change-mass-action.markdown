---
layout: default
title: "Magento2: Why is hiding products that slow and how to make it really fast?"
date: 2019-12-07 12:00:00 +0200
categories: [magento2]
tags: [optimization]
description: Changing the visibility of many products to NOT_VISIBLE is really slow because of a badly written observer in Magento. I propose you a plugin upon that observer to make it very fast.
excerpt: Changing the visibility of many products to NOT_VISIBLE is really slow because of a badly written observer in Magento. I propose you a plugin upon that observer to make it very fast.
---

# Magento2 : Why is hiding products that slow and how to make it really fast?

## How to update efficiently an attribute in Magento2?

In order to update the visibility of a product from the code, you may think loading the product, changing its visibility attribute value, and save it is a good option. But if you do that, some side effects will occur, through the beforeSave, afterSave, and all the plugins and observers that will monitor products change.

That's a lot of pieces of code, but to save a single product the overhead won't be noticeable right ? Indeed, though some may say the more code is involved in the process the most likely to fail that process is, most of the time it isn't an issue, but what if you want to bulk update that attribute ?

There is a class, provided by Magento's module-catalog, which allows you to perform that update way faster than iterating through the products and asking the repository/resource to save them : \Magento\Catalog\Model\Product\Action.

{% highlight php %}
$productCollection = $this->getProductToHideCollection();
$this->action->updateAttributes(
    $productCollection->getAllIds(),
    ['visibility' => Visibility::VISIBILITY_NOT_VISIBLE], 
    $storeId
);
{% endhighlight %}

Of course, you can ask Product\Action to update other attributes.

## Why is hiding a product that slow?

Here we are, problem solved, right ? Well, not really, there is an issue with that specific attribute and more precisely with the VISIBILITY_NOT_VISIBLE value.

If you enable MySQL's general log (aka queries log) and you tail -f on it during the process, you'll see a lot of deletions on url_rewrite, one line per product you hide! That's really suboptimal. Let speed that up!

Let have a look to vendor/magento/module-catalog-url-rewrite/Model/Products/AdaptUrlRewritesToVisibilityAttribute.php:

{% highlight php %}
<?php

namespace Magento\CatalogUrlRewrite\Model\Products;

// ...

/**
 *  Save/Delete UrlRewrites by Product ID's and visibility
 */
class AdaptUrlRewritesToVisibilityAttribute
{
    // ...
    
    public function execute(array $productIds, int $visibility): void
    {
        $products = $this->getProductsByIds($productIds);

        /** @var Product $product */
        foreach ($products as $product) {
            if ($visibility == Visibility::VISIBILITY_NOT_VISIBLE) {
                $this->urlPersist->deleteByData(
                    [
                        UrlRewrite::ENTITY_ID => $product->getId(),
                        UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                    ]
                );
            } elseif ($visibility !== Visibility::VISIBILITY_NOT_VISIBLE) {
                // ...
            }
        }
    }

    // ...
}
{% endhighlight %}

The observer loads all the products given by $productIds arguments, to iterate on them. Then, the behaviour depends on the $visibility argument.

* If $visibility !== VISIBILITY_NOT_VISIBLE (= VISIBILITY_IN_CATALOG, VISIBILITY_IN_SEARCH or VISIBILITY_BOTH), then it will build the url and insert or update url_rewrite accordingly.
* Otherwise, it will delete all the url_rewrites for the given products are they are no longer visible directly on the website.

See the problem ? When an article is visible, it is legitimate it loads the products and loops on them to compute an unique URI for all of them, but when you want to hide them, it could delete the url_rewrites without neither a loop nor the loading of the collection!

## Let speed that up!

Now, we will write a plugin around the execute method of AdaptUrlRewritesToVisibilityAttribute, that won't interfere with the base behaviour when dealing with visible products, but will make the hiding really fast.
Don't forget to add the registration.php and module.xml, and to enable the module so that everything works well.

### The PHP code
{% highlight php %}
<?php

namespace Ariviere\Magentoptimizer\Plugin;

use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogUrlRewrite\Model\Products\AdaptUrlRewritesToVisibilityAttribute as Subject;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

class AdaptUrlRewritesToVisibilityAttribute
{
    private $urlPersist;

    public function __construct(UrlPersistInterface $urlPersist)
    {
        $this->urlPersist = $urlPersist;
    }

    public function aroundExecute(Subject $subject, \Closure $proceed, array $productIds, int $visibility): void
    {
        if ($visibility === Visibility::VISIBILITY_NOT_VISIBLE) {
            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID => $productIds,
                UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
            ]);
        } else {
            $proceed($productIds, $visibility);
        }
    }
}
{% endhighlight %}

### The XML
{% highlight xml %}
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="\Magento\CatalogUrlRewrite\Model\Products\AdaptUrlRewritesToVisibilityAttribute">
        <plugin name="ariviere_magentoptimizer_plugin_adapt_url_rewrite_to_visibility_attr" type="\Ariviere\Magentoptimizer\Plugin\AdaptUrlRewritesToVisibilityAttribute" sortOrder="1"/>
    </type>
</config>
{% endhighlight %}


