---
layout: default
title: "Magento2 : Join a table in a search criteria" 
date: 2019-12-28 10:00:00 +0200
categories: [magento2]
---

# How to join a table with a search criteria?

Often, Magento modules that need to store additional informations to the quote or order (or product, customer...) add them directly into the entity's table, for instance sales\_order.
Sometimes, these data are added through extension attributes, which is way better as it avoid making the rows in the database 

[Vladimir Fishchenko's original article]: https://fishchenko.com/blog/magento-2-join-table-in-orderrepositorygetlist-extension-attributes/
