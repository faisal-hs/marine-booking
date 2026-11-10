<?php

return [
    'popular_searches' => ['Diving', 'Yacht', 'Jet Ski', 'Fishing'],
    'deleted_customer' => 'Deleted customer',
    'coupon' => ['applied_percent' => ':value% discount applied', 'applied_fixed' => ':value :currency discount applied'],

    'cancellation' => [
        'full' => 'Cancel more than :hours hours before the activity for a full refund.',
        'partial' => 'Cancel between :hours and :from hours before the activity for a :percent% refund.',
        'none' => 'Cancel less than :hours hours before the activity and nothing is refunded.',
        'titles' => ['full' => 'Free cancellation', 'partial' => 'Partial refund', 'none' => 'Late cancellation'],
        'how' => ['title' => 'How refunds work', 'body' => 'A refund goes back to the payment method you used. It is worked out from what you paid and when you cancel, and the app shows it before you confirm.'],
    ],

    'notification_titles' => [
        'default' => 'Notification',
        'reservation.created' => 'Booking received',
        'reservation.confirmed' => 'Booking confirmed',
        'reservation.cancelled' => 'Booking cancelled',
        'reservation.expired' => 'Booking expired',
        'payment.failed' => 'Payment failed',
        'payment.refunded' => 'Refund issued',
    ],

    'pages' => [
        'terms' => [
            'title' => 'Terms of Service',
            'sections' => [
                ['title' => 'Acceptance of Terms', 'body' => 'By using Bahriyat, you agree to these terms.'],
                ['title' => 'User Accounts', 'body' => 'You must be at least 18 years old to create an account.'],
                ['title' => 'Bookings and Payments', 'body' => 'A booking is confirmed once its payment is confirmed. Prices are in Saudi Riyal and include any service fee and VAT shown at checkout.'],
                ['title' => 'Providers', 'body' => 'Activities are run by independent providers. Bahriyat connects you with them and holds the payment until the booking is confirmed.'],
            ],
        ],
        'privacy' => [
            'title' => 'Privacy Policy',
            'sections' => [
                ['title' => 'Data We Collect', 'body' => 'We collect your name, phone number and booking history.'],
                ['title' => 'How We Use It', 'body' => 'We use your data to run your bookings, to contact you about them and to improve the app.'],
                ['title' => 'Deleting Your Account', 'body' => 'You can delete your account from the app. Your personal details are removed; records we must keep for accounting are kept without your name.'],
            ],
        ],
        'cancellation-policy' => ['title' => 'Cancellation Policy'],
    ],

    'faqs' => [
        ['category' => 'bookings', 'question' => 'How do I cancel a booking?', 'answer' => 'Open the booking and tap Cancel. :full_policy'],
        ['category' => 'bookings', 'question' => 'How do I know my booking is confirmed?', 'answer' => 'A booking is confirmed as soon as your payment is confirmed. You will get a notification.'],
        ['category' => 'payments', 'question' => 'How do refunds work?', 'answer' => 'A refund goes back to the payment method you used, for the share your cancellation entitles you to.'],
        ['category' => 'payments', 'question' => 'Can I use a coupon?', 'answer' => 'Yes. Enter the code at checkout; it applies to activities of the provider that made it.'],
        ['category' => 'account', 'question' => 'How do I delete my account?', 'answer' => 'Go to Profile and choose Delete account. You need to have no upcoming bookings.'],
    ],

    'about' => ['mission' => "Bahriyat connects adventure seekers with Saudi Arabia's finest marine experience providers."],
];
