# email-bouncer-api
> A simple api to handle email bounces

# Features
- [x] hard bounce - block/blacklist almost permanently for (8^7 ~ approximately 4 years) minutes
- [x] soft bounce - block exponentially in multiple of 8^n, i.e. 8, 64, 512, 4096, etc... in minutes.  Where n is the number of consecutive soft bounces.  When soft bounce expired, it restart to 8 minutes
- [x] index - home endpoint can be use for healthcheck and also for cleanup of expired bounce with query string `/?purge=true`
- [x] handle SES->SNS webhook events

# Development

To run locally:
```
cp .env.example.ini .env.ini
composer install
php -S 0.0.0.0:8888 -t public
```

To run with docker:
```
cp .env.example.ini .env.ini
composer install
docker-compose up -d
```

Then visit: http://localhost:8888

Tip for composer in production, simply execute:
```
docker pull composer
docker run --rm --interactive --tty \
  --volume $PWD:/app \
  composer composer install --no-dev --optimize-autoloader
```

## API

### POST|GET /api/v1/bounces/complaint?email=a-valid@email.com
> Record email as soft bounce - block similar to soft bounce

Complaint is in between a soft bounce and a hard bounce.  It could be because the emailing System scan attachment and detect as a virus, or an actual User complaint with their email Provider.

### POST|GET /api/v1/bounces/hard?email=a-valid@email.com
> Record email as hard bounce - block for 1 year

### POST|GET /api/v1/bounces/remove?emails=a-invalid@email.com,b-invalid@email.net,c-invalid@email.org
> Bulk remove emails from bounce

### POST|GET /api/v1/bounces/soft?email=a-valid@email.com
> Record email as soft bounce - block incremental of 1 day

1. First soft bounce, block for 1 day
2. Second soft bounce within 1 day, block for 2 days
3. Third soft bounce within 2 day, block for 3 days
4. Forth soft bounce within 3 day, block for 4 days
5. And so on... up to 365 days max

### GET /api/v1/bounces/stat?email=a-valid@email.com
> Determine if email is sendable

Response:
```json
{
   "throttle": "number of seconds before email is sendable, negative number implies email is sendable",
   "sendable": true/false
}

```

This is probably the most common use method.  Let say you want to send and email:
1. First, hit `/api/v1/bounces/stat?email=a-valid@email.com` to determine if you can send the email.
2. You don't have to parse the json, simply do `response.indexOf('true') > 0` to determine if you can send email.
3. Do not send email if check is not true.

**Note**: this response has a 20 minutes cache.  This will help reduce the number of API calls when it is place behind some kind of a CDN (Content Delivery Network) Service.

### GET /api/v1/bounces/stats?emails=a-invalid@email.com,b-invalid@email.net,c-invalid@email.org
> Provide a list of emails to check

Result contain only emails that are found (previously bounced); if we do not have a record of the email, then it's probably sendable:
```json
{
   "a-invalid@email.com": "number of seconds before email is sendable, negative number implies email is sendable",
   "b-invalid@email.net": "same as above"
}
```

### GET /api/v1/bounces/aws-ses
> This endpoint is use for handling AWS SES->SNS subscription/webhook.  See also - https://docs.aws.amazon.com/ses/latest/DeveloperGuide/event-publishing-retrieving-sns-examples.html 

This feature makes it all worth it.

# Point of interest
Use this with [Haraka](https://haraka.github.io/) smtp server.  I may create a Haraka plugin for this service.

# MIT
