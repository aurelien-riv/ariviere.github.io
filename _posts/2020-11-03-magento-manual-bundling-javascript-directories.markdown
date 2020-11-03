---
layout: default
title: "Magento2 : Manually bundling the JavaScript files of a directory"
description: Combine several requirejs modules into a single file to speed up Magento, without enabling advanced bundling.
excerpt: Combine several requirejs modules into a single file to speed up Magento, without enabling advanced bundling. 
date: 2020-11-03 20:45:00 +0200
categories: [magento2]
tags: [requirejs, performance]
---

# How to bundle the RequireJS modules from a directory into a single file?

## Existing solutions

Magento's JavaScript is modular, which is great for developing, but far from being efficient in production. 
You can enable merging and bundling so you reduce the amount of file your customers will have to download, but Magento's bundling is worse than doing nothing: 
it will combine your files so that all of your scripts are available from only a few but really heavy files.
These bundles are not organized, there are not common bundles and some more specific to a given page type, such as product, category or checkout pages, and the clients will probably need all of them to run your site. The amount of transmitted data has increased, but most of the code won't be used.

However, you can also try [Magento's advanced bundling](Magento's advanced bundling), which will try to solve that problem, but which also need several manual steps. 
Third-party modules also try to achieve the same goal, with more automation, such as [Baler](magento/baler) or [Magepack](magesuite/magepack) to name but a few. 

I tried to use Magepack, which looked promising, except when I started to encounter errors dued to third-party modules using non-AMD scripts, that no longer worked when called from the bundle, and when I saw there was absolutely no documentation (and probably no solution) for multi-theme stores. I then decided to make my own - basic and specific - bundler to avoid tens of downloads, but without risks, instead of trying to set up a perfect solution - which may not exist - and probably end up with no bundling at all.

## My own solution

Bundled files will be stored in the theme directory, in app/design. That means a static:content deploy won't change the code if you upgrade Magento or a file involved in one of your bundles.

The bundle file will be the main file of the group. I chose that as a convention, I don't think it would change anything if you don't follow it but that's how I did it.

As the main file will contain all the bundled modules, if you wanted to override the file before bundling it, you could name the overridden module "*module.override.js*" for instance, and adapt the script accordingly, it shouldn't be hard to do.

In my case I bundled *Magento\_Ui/web/js/lib/logger/console-logger.js* and *Magento\_Ui/web/js/lib/knockout/bootstrap.js* with their respective dependencies. That's what I'll show you there.
It's not generic, but if you understand the logic and you have a basic Bash understanding, you should be able to adapt it to other cases.

### Step 1: Combine the files

{% highlight shell %}
vendor="Magento"
theme="luma"

rm app/design/frontend/$vendor/$theme/Magento_Ui/web/js/lib/logger/console-logger.js
(
    find vendor/magento/module-ui/view/base/web/js/lib/logger/ -iname "*.js" \
      | while read f; do
          moduleName="Magento_Ui/js/lib/logger/$(echo $f | sed -e 's#^vendor/magento/module-ui/view/base/web/js/lib/logger/##' -e 's/.js$//')";
          appDesignFile="app/design/frontend/[Vendor]/[Theme]/Magento_Ui/$(echo $f | sed 's#^vendor/magento/module-ui/view/base/##')";
          if [ -f $appDesignFile ]; then
              f=$appDesignFile
          fi
          sed -e "s#^define(\[#define('$moduleName', \[#" \
              -e "s#^define(function#define('$moduleName', function#g" \
              -Ee "s#('|\")\./([^\.])#\1$(dirname $moduleName)/\2#g" \
              $f;
    done
) | sponge app/design/frontend/$vendor/$theme/Magento_Ui/web/js/lib/logger/console-logger.js

rm app/design/frontend/$vendor/$theme/Magento_Ui/web/js/lib/knockout/bootstrap.js
(
    find vendor/magento/module-ui/view/base/web/js/lib/knockout/ -iname "*.js" \
      | grep -v -e "knockout/bindings/color-picker.js"   \
                -e "knockout/bindings/resizable.js"      \
                -e "knockout/bindings/range.js"          \
                -e "knockout/bindings/datepicker.js"     \
                -e "knockout/bindings/staticChecked.js"  \
                -e "knockout/bindings/simple-checked.js" \
      | while read f; do
          moduleName="Magento_Ui/js/lib/knockout/$(echo $f | sed -e 's#^vendor/magento/module-ui/view/base/web/js/lib/knockout/##' -e 's/.js$//')";
          appDesignFile="app/design/frontend/$vendor/$theme/Magento_Ui/$(echo $f | sed 's#^vendor/magento/module-ui/view/base/##')";
          if [ -f $appDesignFile ]; then
              f=$appDesignFile
          fi
          sed -e "s#^define(\[#define('$moduleName', \[#" \
              -e "s#^define(function#define('$moduleName', function#g" \
              -Ee "s#\.+/template/#Magento_Ui/js/lib/knockout/template/#g" \
              -Ee "s#\.+/extender/#Magento_Ui/js/lib/knockout/extender/#g" \
              -Ee "s#\.+/bindings/#Magento_Ui/js/lib/knockout/bindings/#g" \
              -Ee "s#\.\./\.\./logger/#Magento_Ui/js/lib/logger/#g" \
              -Ee "s#('|\")\./([^\.])#\1$(dirname $moduleName)/\2#g" \
              $f;
      done
) | sponge app/design/frontend/$vendor/$theme/Magento_Ui/web/js/lib/knockout/bootstrap.js
{% endhighlight %}

## Step 2 : Tell RequireJS your modules are bundled

First, we need to get the list of the bundled modules in each of the bundles:

{% highlight shell %}
vendor="Magento"
theme="luma"

grep -oP "^define\('\K([^']+)" app/design/frontend/$vendor/$theme/Magento_Ui/web/js/lib/logger/console-logger.js
grep -oP "^define\('\K([^']+)" app/design/frontend/$vendor/$theme/Magento_Ui/web/js/lib/knockout/bootstrap.js
{% endhighlight %}

Then, we add to a requirejs-config.js from an enabled Magento module the bundle definitions:

{% highlight js %}
var config = {
    bundles: {
        'Magento_Ui/js/lib/logger/console-logger': [
            'Magento_Ui/js/lib/logger/entry-factory',
            'Magento_Ui/js/lib/logger/entry',
            'Magento_Ui/js/lib/logger/logger-utils',
            'Magento_Ui/js/lib/logger/levels-pool',
            'Magento_Ui/js/lib/logger/formatter',
            'Magento_Ui/js/lib/logger/logger',
            'Magento_Ui/js/lib/logger/console-output-handler',
            'Magento_Ui/js/lib/logger/message-pool'
        ],
        "Magento_Ui/js/lib/knockout/bootstrap" : [
            'Magento_Ui/js/lib/knockout/template/renderer',
            'Magento_Ui/js/lib/knockout/template/loader',
            'Magento_Ui/js/lib/knockout/template/observable_source',
            'Magento_Ui/js/lib/knockout/template/engine',
            'Magento_Ui/js/lib/knockout/extender/observable_array',
            'Magento_Ui/js/lib/knockout/extender/bound-nodes',
            'Magento_Ui/js/lib/knockout/bindings/after-render',
            'Magento_Ui/js/lib/knockout/bindings/bind-html',
            'Magento_Ui/js/lib/knockout/bindings/mage-init',
            'Magento_Ui/js/lib/knockout/bindings/outer_click',
            'Magento_Ui/js/lib/knockout/bindings/i18n',
            'Magento_Ui/js/lib/knockout/bindings/bootstrap',
            'Magento_Ui/js/lib/knockout/bindings/optgroup',
            'Magento_Ui/js/lib/knockout/bindings/collapsible',
            'Magento_Ui/js/lib/knockout/bindings/tooltip',
            'Magento_Ui/js/lib/knockout/bindings/fadeVisible',
            'Magento_Ui/js/lib/knockout/bindings/keyboard',
            'Magento_Ui/js/lib/knockout/bindings/autoselect',
            'Magento_Ui/js/lib/knockout/bindings/scope'
        ]
    }
};
{% endhighlight %}

And of course, we don't forget to run setup:static-content:deploy!

[Magento's advanced bundling]: https://devdocs.magento.com/guides/v2.4/performance-best-practices/advanced-js-bundling.html
[Baler]: https://github.com/magento/baler
[Magepack]: https://github.com/magesuite/magepack
