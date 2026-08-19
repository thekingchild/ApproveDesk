# Webhooks

Open **Administration → Webhooks** to send signed events to another system when a request moves.

Create an endpoint with a name, HTTPS URL, and the events you care about: submitted, advanced, completed, rejected, returned, information requested, delegated.

ApproveDesk signs each delivery. Copy the signing secret when it is shown. You will not see the same secret again. Rotate it from the endpoint if it leaks.

Failed deliveries retry. Inactive endpoints stop sending but keep their history.

Do not point a webhook at an untrusted URL. Only HTTPS destinations are accepted.
