---
layout: default
title: "Magento2 : Is your site full-page cacheable?"
date: 2019-12-21 10:00:00 +0200
categories: [magento2]
tags: [performance, full-page-cache, fpc]
---

# Is your Magento2 website full-page cacheable?

## What if Full Page Cache (aka FPC)?
Magento uses many cache types by default: layout, reflection, block_html... and you can add yours if you have specific things to cache. But amongst them, you also have fullpage, a cache that stores the entire page result.

However, it cannot cache every pages: what would happen if you try to cache the whole customer account page? 

First, when a page is cacheable and FPC enabled, Magento's models that hold the session are impersonated, which mean you won't serve another customer's data on your cache, **except** if you use $\_SESSION. That behavior avoids data leaks but also leads to weird behaviors once in production if your site is not well tested (as you don't use FPC on your development machine). 

Then, there are by default non cacheable blocks on that page, that prevents FPC to do its work. Customer account page won't be cached by default, but your custom pages will be.

### How to make a page non cacheable?

As long as at least one block on your page is not cacheable, the whole page is not eligible to FPC. But, beware, if your block says its cache key or cache lifetime is null, it won't be cached on **block\_html** cache, but FPC will still cache the page.

To prevent full page caching, your block **must** set the cacheable attribute to false in its layout's XML file!

**Not Full Page Cacheable:**
{%highlight xml %}
<block class="Magento\Framework\View\Element\Template" name="my.block" cacheable="false/>
{%endhighlight %}

**Still Full Page Cacheable** (but not block_html cacheable) :

{%highlight php %}
class MyBlock extends Template
{
    public function getCacheLifetime()
    {
        return null;
    }

    public function getCacheKey()
    {
        return null;
    }
}
{%endhighlight %}

## Are my pages full page cacheable?
On a default installation, many pages are not cacheable: customer pages, cart, order confirmation, and so on. CMS pages, product lists and pages will be so.

When you add modules (you purchased or you developed), you may wonder whether your cache is still fully operational. 

Bad news, there is no command for that. Actually there a no command to dump the routes, so Magento won't help you at all.

But I did that command for you!

### My FullPage Cache Debugger

<a href="/downloads/Ari_PageCacheDebug.zip">Click here to get the code (zip)</a> 

There are two commands, one builds the layout for a given theme and layout page, and the other one iterates through layout pages and themes and asks the first one whether that combination is cacheable.

You can provide a list of themes to check with, usefull to avoid losing time on the themes you don't use directly, for instance Magento/Blank.

Here a sample of a possible output:
```
+--------------------------------+---------------+--------------+
|             Layout             | Magento/blank | Magento/luma |
+--------------------------------+---------------+--------------+
|  catalogsearch_advanced_index  |       ✔       |      ✔       |
|     catalog_category_view      |       ✔       |      ✔       |
| catalogsearch_advanced_result  |       ✔       |      ✔       |
|   catalogsearch_result_index   |       ✔       |      ✔       |
|      default_head_blocks       |       ✔       |      ✔       |
| customer_account_logoutsuccess |       ✔       |      ✔       |
|     customer_address_index     |       ☓       |      ☓       |
|     customer_account_index     |       ☓       |      ☓       |
|     customer_account_edit      |       ☓       |      ☓       |
|     customer_account_login     |       ☓       |      ☓       |
| customer_account_confirmation  |       ✔       |      ✔       |
+--------------------------------+---------------+--------------+
```
