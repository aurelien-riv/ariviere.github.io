---
layout: default
title: "Why is Gitlab-Runner stuck when an error occurs with a background task?"
description: "A background task, such as a VPN connection, will prevent the stage to finish until it timeouts"
excerpt: "How to handle background jobs with Gitlab-CI so that our stage don't get stuck until a timeout error?"
date: 2022-11-21 22:52:00 +0200
categories: [Devops]
tags: [Gitlab, CI/CD, Bash]
---

# Why is Gitlab-Runner stuck when an error occurs with a background task (and how to handle it properly)?

You may need to launch a background script in your CI/CD pipeline. For instance, if you deploy through a VPN, you'll
probably start your stage with a command to open the tunnel, followed by an ampersand to make it run in the background
(and maybe a sleep to wait for the connection to be ready).

If you do that, you'll have to stop the process, using kill or pkill for instance, or your shell script will not end, 
until your runner stops it because of its timeout.

If an error occurs, your script will terminate (Gitlab runs Bash with some flags in order to immediately stop a job 
instead of allowing a broken artifact to be published), but the background task won't be interrupted, and the runner 
will still wait for its completion. 

**TL;DR:** we have to stop our background tasks when the script succeed but also when it fails.  

## Can we handle that in the after_script ?

To run an action after the *script* section, the best location is *after_script*. Indeed, it is called even if the 
script fails. However, it won't be called before the script part ends, so it won't help in this situation.

By the way, if you want to launch a background task in the *before_script* section, you'll certainly have to close it 
in that section too, and open it again in the *script* block if you need it (in that case, using a single script section
seems more appropriate).

## Using trap

POSIX shells have no try-catch-finally features. Instead, the *trap* built-in allows us to catch some signals (such as
SIGINT or SIGQUIT), but not scripting errors. However, Bash extends trap to handle them, so we can use it to close our 
background jobs. If you're not using Bash, you can search for an equivalent with your shell interpreter if there is any.

Here's an example:

```yaml
my_stage:
  script: # language=sh
    - sudo openfortivpn -c /etc/openfortivpn/my-tunnel-config.conf > /dev/null & sleep 5'
    - |
      cleanup() { 
        echo "trap $1"; 
        sudo pkill openfortivpn;
      }
      set -Ee;
      trap cleanup 1 2 3 6 ERR;

    - #[...]
    - sudo pkill openfortivpn
```

So, I open an openfortivpn ppp connection, and I wait for 5 seconds to be sure it's ready (I could also use a loop and a
ping probe for instance, but I know 5 seconds are enough for my gateway).

Then, I define a cleanup function that will first echo which signal was caught (empty if ERR), and then kill the 
openfortivpn job.

Then, I enabled the errtrace flag (*set -E*, which is equivalent to *set -o errtrace*) to be able to trap ERR.

Finally, I added a trap on SIGHUP, SIGINT, SIGQUIT, SIGABRT, and the non-standard ERR signal.

Once this is set up, I can put the rest of my script.

When I no longer needs the tunnel, I still close explicitly the job.

## (Out of topic) Side note and security concerns in my example

The language=sh comment is useful for Jetbrains' IDEs, so they understand the whole array is a language injection. 
They'll provide both syntactic coloration and analysis if you do so. 

In my example, I run openfortivpn and pkill using sudo in the script, of course without password. To do so, you have
to whitelist the full command-line (program and arguments) in the sudoers, without wildcard characters, unless you want
the people that write the scripts to be able to get root privileges on your runner instance.

```text
gitlab-runner ALL=(ALL) NOPASSWD: /usr/bin/openfortivpn -c /etc/openfortivpn/my-tunnel-config.conf
gitlab-runner ALL=(ALL) NOPASSWD: /usr/bin/pkill openfortivpn
```

By the way, no-one except the root user should be allowed to read the content of /etc/openfortivpn/my-tunnel-config.conf.
And if you put the credentials in your project tree, don't forget they won't be private anymore. 


