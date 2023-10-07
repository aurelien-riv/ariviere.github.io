---
layout: default
title: "Draw W3WP process info per app pool"
description: "Adding app pool name to windows_process w3wp without installing IIS management scripts and tools"
excerpt: "How to plot process info such as memory consumption per app pool name without installing IIS management scripts and tools?"
date: 2023-10-07 19:31:00 +0200
categories: [devops]
tags: [Grafana, Prometheus, windows_exporter, IIS]
---

# Plot per app pool W3WP process information using windows_exporter and Grafana, without IIS management tools

When you use windows\_exporter's process exporter, you need to install IIS Management scripts and tools in order to gather additional data related to IIS.

If, like me, you don't want to install it, there is another option that only relies on windows\_exporter's IIS collector : by joining two series by the process' pid, we can 
enrich the metrics with the app name.

I won't explain neither the installation of windows\_exporter nor the metrics I use, you'll find everything you need on [windows_exporter](their Github).

```text
# HELP windows_iis_worker_current_requests Current number of requests being processed by the worker process
# TYPE windows_iis_worker_current_requests counter
windows_iis_worker_current_requests{app="Pool1",pid="10268"} 0
windows_iis_worker_current_requests{app="Pool3",pid="10764"} 0
windows_iis_worker_current_requests{app="Pool2",pid="14532"} 0
# HELP windows_process_working_set_private_bytes Size of the working set, in bytes, that is use for this process only and not shared nor shareable by other processes.
# TYPE windows_process_working_set_private_bytes gauge
windows_process_working_set_private_bytes{creating_process_id="11248",process="w3wp",process_id="10268"} 1.5874048e+08
windows_process_working_set_private_bytes{creating_process_id="11248",process="w3wp",process_id="10764"} 5.87776e+06
windows_process_working_set_private_bytes{creating_process_id="11248",process="w3wp",process_id="14532"} 2.4336384e+08
```

## Per PID information

```
windows_process_working_set_private_bytes{instance="$instance", process="w3wp"}
    * on(process_id) group_left(app)
    clamp_min(
        clamp_max(
            label_replace(windows_iis_worker_current_requests{instance="$instance"}, "process_id", "$1", "pid", "(.*)"),
            1
        ),
        1
    )
```

Here, Prometheus will multiply the values of the two series, joined by their process ids. However, we have to transform the data: using `label_replace`, we turn windows\_iis\_worker\_current\_requests' pid label into process\_id so that both metrics use the same label name ; and using `clamp_min` and `clamp_max`, we replace all the collected data by the value , so that the multiplication won't alter the first metric values.

With that query, we may have multiple series for a single app pool, either because there are several worker process I guess, or simply because of recycling. 

## Per process name

It is sometimes interesting to view them separately (to understand whether a new process was created or the current one freed its memory, in case of a RAM usage drop for instance), but you can also sum them to get per app name series instead of per pid:

```
sum by (app) (
    windows_process_working_set_private_bytes{instance="$instance", process="w3wp"}
    * on(process_id) group_left(app)
    clamp_min(
        clamp_max(
            label_replace(windows_iis_worker_current_requests{instance="$instance"}, "process_id", "$1", "pid", "(.*)"),
            1
        ),
        1
    )
)
```

[windows_exporter]: https://github.com/prometheus-community/windows_exporter
