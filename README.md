# email-bouncer-api
A simple api handle email bounce.

* hard bounce - block/blacklist almost permanently for (8^8) minutes.
* soft bounce - block for 8 minutes, exponentially in multiple of 8^n, i.e. 8, 64, 512, 4096, etc... in minutes.  Where n is the number of soft bounces.  When a soft bounce expired, it restart at 8 minutes.
* index - home endpoint can be use for healthcheck and also for cleanup of expired bounce with query string `/?purge=true`

# MIT
