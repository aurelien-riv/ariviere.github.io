---
layout: default
title:  "That patch on ProductMetadata can speed up your whole Magento2 website!"
date:   2020-03-17 08:00:00 +0200
categories: [magento2]
tags: [cache, performance]
description: Because of a bug on Magento Framework, version information are never stored in the cache. That simple fix can improve your performances in production.
excerpt: Because of a bug on Magento Framework, version information are never stored in the cache. That simple fix can improve your performances in production.
---

# Patching ProductMetadata can speed up all of your Magento2 pages

Several Magento2 modules want to know on which Magento release they're ran in order to adapt their behaviour accordingly.
If one of them does that on your project, that post may save your CPU time.

You may think the version number is located in a constant in Magento Framework, but it's not. In fact, Magento uses
Composer to gather information from all the vendor that are installed, in order to detect its own version. 

Loading the composer.lock using Composer implies validating it through a JSON Schema, which ensure the file valid, 
but costs time and memory.

Magento iterates over all the vendors until it finds "magento/product-\*", and returns it version. 

When a version is found, ProductMetadata stores it in a property, but never in the cache because of an instruction located in the wrong condition.

You can find more information [on that bug report][Github#24025]

## Patching it

First, you need to install composer-patch (I think it is installed by default with Magento).

```
$ composer require cweagans/composer-patches
```

Then, create the patches/ directory at the root of your project, and download [that patch][fix-composer-version-lookup.patch] 
and save it as _patches/fix-magento-version-not-caching.patch_.

```
From 16ab63b347dd4628ed1cc1947929ff44b6bad90c Mon Sep 17 00:00:00 2001
From: Lukasz Lewandowski <luklewluk@gmail.com>
Date: Wed, 11 Dec 2019 15:25:39 -0600
Subject: [PATCH] Fix caching Product Metadata getVersion

---
 App/ProductMetadata.php | 2 +-
 1 file changed, 1 insertion(+), 1 deletion(-)

diff --git a/App/ProductMetadata.php b/App/ProductMetadata.php
index 052119713294..8f989351743d 100644
--- a/App/ProductMetadata.php
+++ b/App/ProductMetadata.php
@@ -85,8 +85,8 @@ public function getVersion()
                 } else {
                     $this->version = 'UNKNOWN';
                 }
-                $this->cache->save($this->version, self::VERSION_CACHE_KEY, [Config::CACHE_TAG]);
             }
+            $this->cache->save($this->version, self::VERSION_CACHE_KEY, [Config::CACHE_TAG]);
         }
         return $this->version;
     }
```

Then, add that into your composer.json :

{% highlight javascript %}
	"extra": {
		"magento-force": "override",
		"composer-exit-on-patch-failure": true,
		"patches": {
			"magento/framework": {
				"Patch magento version never saving in cache": "patches/fix-magento-version-not-caching.patch"
			}
		}
	}
{% endhighlight %}

Finally, let apply it:

```
$ composer update "magento/framework"
```

Now, if that method was used in your project, you should notice a performance boost.

[Github#24025]: https://github.com/magento/magento2/issues/24025
[fix-composer-version-lookup.patch]: https://github.com/magento/magento2/files/4112454/magento-framework-github-24025-fix-composer-version-lookup.patch.txt
