describe('InterSoccer Order Email Attributes', () => {
    beforeEach(() => {
        // Login as a customer
        cy.loginAsCustomer();
        // Add a camp product to cart with attributes
        cy.visit('/shop'); // Adjust to actual shop page
        cy.get('.product').first().click();
        cy.get('#player_assignment').select('1'); // Select a player
        cy.get('#camp_days').select(['Monday', 'Tuesday']); // Select camp days
        cy.get('.single_add_to_cart_button').click();
        cy.visit('/checkout'); // Adjust to actual checkout page
        cy.get('#payment_method_cod').check(); // Select cash on delivery for testing
        cy.get('#place_order').click();
    });

    it('should include variation meta and visible, non-variation parent attributes in the cart, checkout, and processing order email exactly once', () => {
        // Verify in cart
        cy.visit('/cart');
        cy.get('.cart_item').should('contain', 'Assigned Attendee:');
        cy.get('.cart_item').should('contain', 'Days Selected: Monday, Tuesday');
        cy.get('.cart_item').should('contain', 'InterSoccer Venues:');
        cy.get('.cart_item').should('contain', 'Camp Terms:');
        cy.get('.cart_item').should('contain', 'Camp Times:');

        // Verify in checkout
        cy.visit('/checkout');
        cy.get('.woocommerce-checkout-review-order').should('contain', 'Assigned Attendee:');
        cy.get('.woocommerce-checkout-review-order').should('contain', 'Days Selected: Monday, Tuesday');
        cy.get('.woocommerce-checkout-review-order').should('contain', 'InterSoccer Venues:');
        cy.get('.woocommerce-checkout-review-order').should('contain', 'Camp Terms:');
        cy.get('.woocommerce-checkout-review-order').should('contain', 'Camp Times:');

        // Verify in processing order email
        cy.request('GET', '/wp-json/wc/v3/orders').then((response) => {
            const orderId = response.body[0].id;
            cy.task('getLatestEmail', { orderId }).then((emailContent) => {
                // Check variation-specific attributes and meta
                expect(emailContent).to.contain('Booking Type:');
                expect(emailContent).to.contain('Age Group:');
                expect(emailContent).to.contain('Assigned Attendee:');
                expect(emailContent).to.contain('Days Selected: Monday, Tuesday');

                // Check non-variation, visible parent attributes
                expect(emailContent).to.contain('InterSoccer Venues:');
                expect(emailContent).to.contain('Camp Terms:');
                expect(emailContent).to.contain('Camp Times:');

                // Verify no duplication
                const venueCount = (emailContent.match(/InterSoccer Venues:/g) || []).length;
                expect(venueCount).to.equal(1, 'InterSoccer Venues attribute should appear exactly once');
                const termsCount = (emailContent.match(/Camp Terms:/g) || []).length;
                expect(termsCount).to.equal(1, 'Camp Terms attribute should appear exactly once');
                const timesCount = (emailContent.match(/Camp Times:/g) || []).length;
                expect(timesCount).to.equal(1, 'Camp Times attribute should appear exactly once');
                const attendeeCount = (emailContent.match(/Assigned Attendee:/g) || []).length;
                expect(attendeeCount).to.equal(1, 'Assigned Attendee meta should appear exactly once');
                const daysCount = (emailContent.match(/Days Selected:/g) || []).length;
                expect(daysCount).to.equal(1, 'Days Selected meta should appear exactly once');
            });
        });
    });

    it('should include variation meta and visible, non-variation parent attributes in the completed order email exactly once', () => {
        // Simulate order completion
        cy.request('POST', '/wp-json/wc/v3/orders/1', { status: 'completed' });
        cy.request('GET', '/wp-json/wc/v3/orders').then((response) => {
            const orderId = response.body[0].id;
            cy.task('getLatestEmail', { orderId }).then((emailContent) => {
                // Check variation-specific attributes and meta
                expect(emailContent).to.contain('Booking Type:');
                expect(emailContent).to.contain('Age Group:');
                expect(emailContent).to.contain('Assigned Attendee:');
                expect(emailContent).to.contain('Days Selected: Monday, Tuesday');

                // Check non-variation, visible parent attributes
                expect(emailContent).to.contain('InterSoccer Venues:');
                expect(emailContent).to.contain('Camp Terms:');
                expect(emailContent).to.contain('Camp Times:');

                // Verify no duplication
                const venueCount = (emailContent.match(/InterSoccer Venues:/g) || []).length;
                expect(venueCount).to.equal(1, 'InterSoccer Venues attribute should appear exactly once');
                const termsCount = (emailContent.match(/Camp Terms:/g) || []).length;
                expect(termsCount).to.equal(1, 'Camp Terms attribute should appear exactly once');
                const timesCount = (emailContent.match(/Camp Times:/g) || []).length;
                expect(timesCount).to.equal(1, 'Camp Times attribute should appear exactly once');
                const attendeeCount = (emailContent.match(/Assigned Attendee:/g) || []).length;
                expect(attendeeCount).to.equal(1, 'Assigned Attendee meta should appear exactly once');
                const daysCount = (emailContent.match(/Days Selected:/g) || []).length;
                expect(daysCount).to.equal(1, 'Days Selected meta should appear exactly once');
            });
        });
    });
});
