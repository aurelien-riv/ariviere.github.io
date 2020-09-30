---
layout: default
title: "Magento2 : How to plot a RequireJS dependency graph?"
description: Draw a SVG map of your javascript files to understand the relationships between your modules. 
excerpt: Draw a SVG map of your javascript files to understand the relationships between your modules.
date: 2020-09-29 22:49:00 +0200 
categories: [magento2]
tags: [requirejs, graphviz, dot]
---

# How to plot a RequireJs dependency graph on Magento?

There are tons of JavaScript files on a standard Magento installation, and even more when you add yours and some additional vendors. 
Some are loaded directly from the DOM, some are included through mage-init attributes, some will be a dependency of a while module thanks to the deps part of requirejs-config.js files. 
But of course, most of them are only loaded because of others module depending on them.

I found no way to visualize that dependency chain, so I made a basic solution by myself. I hope it may help someone else.

You have to disable JS minification and merging to follow these steps.

## Step one : logging modules loading

Open your favourite browser's debugger and select /pub/static/[...]/frontend/[THEME]/[LANG]/requirejs/require.js. 

Then, search for the method completeLoad and add a logpoint on *callGetModule(args);* by right clicking on the line number. It's on line 1544, at least for Magento 2.3.5-p2 and RequireJS 2.1.11.

In the console output expression, paste that snippet :
{% highlight js %}
'"' + moduleName + '"' + (typeof args[1] !== "undefined" && args[1].length > 0 ? (" -> {\"" + args[1].join('\",\"') + "\"}") : "")
{% endhighlight %}

Reload the page and copy the messages from your logpoint.

### Report text/x-magento-init loaded scripts

When the scripts are loaded using \<script type="text/x-magento-init"\> tags, it would be usefull to show in the graph why the were loaded, and the part of the DOM they are related to.

We can get that piece of information by putting a logpoint on mage/apply/scripts.js on processElems(). Add that expression on the *if (selector === '\*')* line, before *addVirtual(components)* :
{% highlight js %}
'"' + selector + '" [shape=record];' + '"./mage-init" -> "' + selector + '" -> {"' + Object.keys(components).join('", "') + '"}'
{% endhighlight %}

### Highlight RequireJS-config deps

Some scripts will be loaded automatically by RequireJS as they are present on requirejs-config.js's config['deps'] array.

Open /pub/static/[...]/frontend/[THEME]/[LANG]/requirejs-config.js and on the line that contains require.config(config);, add the following logpoint :

{% highlight js %}
config.deps.length ? ('"requirejs.config.deps" [shape=octogon]; "requirejs.config.deps" -> {"' + config.deps.join('", "') + '"}') : ''
{% endhighlight %}

## Step two : formatting the dot file

Create a text file, requirejs-graph.dot for instance, and open it. I recommend using vim or neovim for that as it recognize graphviz files out of the box.

On that file, you'll paste the logs here :

{% highlight dot %}
strict digraph {
graph [splines=polyline,ranksep=3]

// HERE

}
{% endhighlight %}

Then, remove any line that doesn't come from your logpoint.

Finally, you'll have to remove the file, line and column indication (and maybe the timestamp) or the messages. In my case, I typed these commands in vim to do so :
```
:%s/require.js:1544:20//
:%s/\d* scripts.js:63:12//
:%s/requirejs-config.js:56//
```

## Step three : bind mixins to their module

Relationships between mixins and the module they are associated are not reported by my logpoint expression, so we will fix that using grep :

{% highlight sh %}
grep -oE 'mixins[^\"]+' requirejs-graph.dot | sort | uniq | awk '{print "\""$1"\" -> \""substr($1, 8)"\""}'; echo '"domReady!" -> "domReady"'
{% endhighlight %}

Add the output of that command at the end of your file, before the last curly bracket. 

## Step four : compile your graph into SVG

Now, simply run that command to get your (awful) SVG graph :
{% highlight sh %}
dot -Tsvg requirejs-graph.dot > requirejs-graph.svg
{% endhighlight %}

Of course, you need to have the dot executable, that usually comes from the graphviz package of the GNU/Linux distribution.

![Example of requirejs-graph.svg file](/media/requirejs-graph.png)
