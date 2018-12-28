# email-bouncer-api
A simple api handle email bounce.

* hard bounce - block/blacklist almost permanently for (8^8 + 8) minutes.
* soft bounce - block for 8 minutes, exponentially in multiple of 8^n + 8, i.e. 16, 72, 520, 4104, etc... in minutes.  Where n is the number of soft bounces.

# MIT
