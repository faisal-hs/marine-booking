<?php

/** The page a customer lands on after paying (or not) at Tabby or Tamara: PaymentReturnController, resources/views/payments/return.blade.php. */
return [
    'return' => [
        'page_title' => 'Payment status',

        'paid' => ['title' => 'Payment received', 'text' => 'Your booking is confirmed. Keep your reference number.'],
        'pending' => ['title' => 'We are confirming your payment', 'text' => 'This can take a minute. This page refreshes by itself.'],
        'failed' => ['title' => 'The payment was not completed', 'text' => 'Nothing was taken from you. You can try again from the app before your booking runs out of time.'],
        'cancelled' => ['title' => 'Payment cancelled', 'text' => 'You cancelled the payment and nothing was taken from you. You can try again from the app before your booking runs out of time.'],
        'expired' => ['title' => 'The payment time ran out', 'text' => 'The booking was released and nothing was taken from you. Please book again.'],

        'reasons' => [
            'declined' => 'The payment provider did not approve the payment.',
            'mismatch' => 'The payment did not match your booking, so it was not taken.',
            'late_payment' => 'The payment arrived after the booking had expired, so it was returned to you.',
            'error' => 'Something went wrong while processing the payment.',
        ],

        'labels' => [
            'amount_paid' => 'Amount paid',
            'amount' => 'Amount',
            'refunded' => 'Refunded',
            'payment_reference' => 'Payment reference',
            'booking_reference' => 'Booking reference',
            'activity' => 'Activity',
            'when' => 'Date',
            'method' => 'Paid with',
            'paid_at' => 'Paid on',
        ],

        'back_to_app' => 'Back to the app',
        'refreshing' => 'Checking again in a few seconds…',
    ],
];
