---
layout: default
title: "Magento2 : Is your site full-page cacheable?"
date: 2019-12-21 10:00:00 +0200
categories: [magento2]
tags: [performance, magento-module-page-cache]
description: To improve your site speed, you should better use FPC. But are you sure you didn't broke it? I propose you a module to analyze your FPC's health.
excerpt: To improve your site speed, you should better use FPC. But are you sure you didn't broke it? I propose you a module to analyze your FPC's health.
---

# Is your Magento2 website full-page cacheable?

## What if Full Page Cache (aka FPC)?
Magento uses many cache types by default: layout, reflection, block\_html... and you can add yours if you have specific things to cache. But amongst them, you also have full\_page, which stores the entire page.

However, it cannot cache every page: what would happen if you try to cache the whole customer account page? 

First, when a page is cacheable and FPC enabled, Magento's models in charge of holding the session data are impersonated, which mean you won't serve another customer's data on your cache, **except** if you use $\_SESSION. That behaviour avoids data leaks but also leads to weird issues once in production, if your site is not well tested (as you don't use FPC on your development machine). 

Then, Magento made that page not cacheable, so FPC won't do anything on it, contrary to your custom pages that will, by default, be cacheable.

### How to make a page non cacheable?

As long as your page contains at least one non cacheable block, it is not eligible to FPC. 
Beware, if your block says its cache key or cache lifetime is null, it won't be cached on **block\_html** cache, but **FPC will still cache the page**.

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
On a default installation, many pages are not cacheable: customer pages, cart, order confirmation, and so on.

When you add modules (you purchased or you developed), you may wonder whether your cache is still fully operational, as the addition (or edition) of a block included on several or all the pages (for instance in the header, the menu...) can entirely break FPC. 

Bad news, Magento doesn't provide a way to check that, but I wrote a module to allow you to inspect it.

### My Full Page Cache Debugger

You can download the code here: <a href="/downloads/Ari_PageCacheDebug.zip">Ari_PageCacheDebug (zip)</a> 

There are two commands, one builds the layout for a given theme and layout page, and the other one iterates through layout pages and themes and asks the first one whether that combination is cacheable.

You can provide a list of themes to check with, useful to avoid losing time on the themes you don't use directly, for instance Magento/Blank.

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
