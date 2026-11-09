# STATUS MAPPING (canonical statuses)
| canonical | meaning | example source words (case-insensitive, '-'/'_' = space, leading 'wc-' removed) |
|---|---|---|
| pending | received, not yet confirmed | pending, new, placed, processing, on hold, awaiting payment/confirmation, unconfirmed, created, open, পেন্ডিং, অপেক্ষমান |
| confirmed | accepted by staff | confirmed, approved, accepted, verified, packed, ready to ship, কনফার্মড, নিশ্চিত |
| shipped | handed to courier | shipped, dispatched, in transit, out for delivery, picked up, with courier, শিপড |
| delivered | customer received | delivered, completed, complete, fulfilled, done, received, successful, ডেলিভারড, সম্পন্ন |
| cancelled | stopped before delivery | cancelled, canceled, rejected, failed, void, declined, বাতিল |
| returned | came back / refunded | returned, refunded, return, rto, return to origin, ফেরত, রিটার্ন |
| other | unknown word | everything else (raw status kept; shown in data-quality report) |
Import and connector screens must list "raw status -> canonical -> count" so the owner can see what was understood. Adding a word = edit the map + add a unit test.
