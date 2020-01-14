---
layout: default
title: "Debugging PHP: Why should you or shouldn't you use a C debugger"
date: 2020-01-14 20:00:00 +0200
categories: [php]
tags: [debugging, php-src, GDB]
description: I wrote several posts on using GDB to debug PHP, but why and when should you use it instead of a real PHP debugger?
excerpt: I wrote several posts on using GDB to debug PHP, but why and when should you use it instead of a real PHP debugger?
---

# Is it a nonsense to use a low level debugger for PHP processes?

I wrote several posts on my blog explaining how you could use GDB, a C debugger, to debug a PHP process. It may seem silly as it was designed to inspect program at runtime by reading it machine code and transcript it to assembly, or to C code if some information from the C program are left into the binary of if debugging symbols for that program are installed. However, if you have no PHP debugger, it may help you a lot, even if it comes with some restrictions. Let see some benefits and drawbacks of that method.

## Limitations
First, you have no promises of backward compatibility here. My code snippets won't work on PHP5 and will probably stop working on PHP8, but worst, I can't be sure they will keep on doing there job with the next minor release, even if most of the time there won't be major changes on the zed\_execute\_data structures as they are at the heart of PHP.

Then, contrary to XDebug, these instructions don't permit (yet?) to add conditions based on variable values to your breakpoints. We could, but the conditions would be really complex as 
* we would have to fetch the variable or argument from internal structures, which is not trivial ;
* we would have to handle properly the value we get, and the process is not the same depending on the data type (object, array, string, int, ...) ;
* we would have to repeat the operation on array and object items, which increases the complexity again

Also, web processes won't wait for you to attach them with GDB, so you'll have to guess which one is yours, and by attaching it while it runs, it may already be too late when you attach it. Plus, attaching the wrong one will pause the loading of someone else's process, maybe one of a customer. However, CLI applications can be started from GDB, so you can put your breakpoints before the PHP binary is launched.

## Advantages
First, XDebug is limited to user defined functions and methods. Using GDB, we can break on "internal" functions (such as file\_exists) and methods (such as PDOStatement::execute), which are defined inside PHP or its modules.

However, contrary to XDebug, only the attached process suffers from performance degradation, instead of all of them.

Yet, using a debugger avoids altering the source code to understand what a process does.

