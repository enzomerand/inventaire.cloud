{{--
    The terms of sale, in English: a translation for information (the French version prevails, see legal/terms-fr). Plain HTML only, like the
    French partial. It receives $seller (see App\Legal\Seller), $plans (App\Landing\PricingPlans) and $termsVersion.

    The articles of the annex are the statutory text and stay in French, word for word as in the French partial (a test compares the two).
--}}
<p><strong>This translation is provided for information; the French version prevails.</strong> <a href="{{ \App\Support\PublicUrls::termsPath('fr') }}" hreflang="fr" lang="fr">Lire les conditions en français</a>.</p>

<h2>Preamble</h2>
<p>These general terms of sale (the "Terms") govern the subscription to the paid "Pro" plan of the online inventory service inventaire.cloud (the "Service"). They are concluded between the seller named in Article 1 (the "Seller") and any person who subscribes to this plan (the "Customer"), whether acting as a consumer, a non-professional or a professional.</p>
<p>The Customer accepts them by ticking the box provided when ordering. They are written in French, the language of the contract. A translation may be provided for information; only the French version prevails.</p>

<h2>Article 1 — Seller</h2>
<p>
    {{ $seller['name'] }}, {{ $seller['form'] }}@if ($seller['capital']), with a share capital of {{ $seller['capital'] }}@endif<br>
    Registered office: {{ $seller['address'] }}<br>
    Registration: {{ $seller['registration'] }}<br>
    Intra-Community VAT number: {{ $seller['vat_number'] }}<br>
    Email: {{ $seller['email'] }} — Telephone: {{ $seller['phone'] }}
</p>

<h2>Article 2 — Service and plans</h2>
<p>The Service lets you create and manage online inventories. The subscription is attached to a workspace: only the owner of the workspace subscribes to it and manages it, and all the members of the workspace benefit from its limits.</p>
<table>
    <thead>
        <tr><th scope="col">Limit per workspace</th><th scope="col">Free</th><th scope="col">Pro</th></tr>
    </thead>
    <tbody>
        <tr><td>Inventories</td><td>{{ $plans->inventories('free') }}</td><td>{{ $plans->inventories('pro') }}</td></tr>
        <tr><td>Items per inventory</td><td>{{ $plans->items('free') }}</td><td>{{ $plans->items('pro') }}</td></tr>
        <tr><td>File storage</td><td>{{ \App\Support\FileSize::format($plans->limit('free', 'storage_bytes')) }}</td><td>{{ \App\Support\FileSize::format($plans->limit('pro', 'storage_bytes')) }}</td></tr>
        <tr><td>Members, pending invitations included</td><td>{{ $plans->members('free') }}</td><td>{{ $plans->members('pro') }}</td></tr>
        <tr><td>Share links per inventory</td><td>{{ $plans->shareLinks('free') }}</td><td>{{ $plans->shareLinks('pro') }}</td></tr>
    </tbody>
</table>
<p>The Free plan is not subject to any payment and falls outside the scope of the sale governed by these Terms.</p>

<h2>Article 3 — Price and VAT</h2>
<p>The Pro subscription costs {{ $plans->monthlyPrice() }} incl. VAT per month{{ $plans->yearlyPrice() === null ? '' : ', or '.$plans->yearlyPrice().' incl. VAT per year' }}, payable in advance. These prices include VAT at the rate applicable in France; the invoice details the amount.</p>
<p>VAT is determined according to the regulations applicable on the date of the invoice and the place where the Customer is established. A professional established in another Member State of the European Union who provides a valid VAT number may be invoiced without tax under the reverse charge mechanism.</p>
<p>The Seller may change its prices for the future. It informs the Customer by email at least thirty days before the due date at which the new price applies. A Customer who refuses it terminates their contract before that date.</p>

<h2>Article 4 — Order</h2>
<p>The owner of the workspace chooses the billing period from the "Subscription" page of the workspace. They are then directed to Stripe's payment page, which summarises the service, the price and the billing period and indicates the accepted payment methods. They accept these Terms, request the immediate start of the Service (Article 7) and confirm their order, which obliges them to pay.</p>
<p>The contract is concluded when the payment is validated. The Seller sends the Customer, on a durable medium, a confirmation together with these Terms and the model withdrawal form.</p>

<h2>Article 5 — Payment</h2>
<p>Payment is processed by Stripe; the Seller has no access to any full card number. The saved payment method is charged on subscription, then at the start of each period. Invoices are available from the management of the subscription.</p>
<p>If a payment fails, new attempts are made and the Customer is informed. If the payment remains unpaid after these attempts, the subscription ends and the workspace returns to the Free plan (Article 9).</p>
<p>For a professional Customer, any late payment automatically gives rise to penalties equal to three times the legal interest rate and a fixed recovery fee of €40.</p>

<h2>Article 6 — Term, renewal and termination</h2>
<p><strong>Monthly subscription.</strong> It is concluded for an indefinite period, with no minimum term, and paid month by month. The Customer may terminate it at any time; it ends at the end of the month already paid.</p>
<p><strong>Yearly subscription.</strong> It is concluded for a term of one year, tacitly renewed for successive periods of one year. The Seller informs the Customer of the possibility of not renewing it, by a dedicated email, at the earliest three months and at the latest one month before the due date. The Customer may request non-renewal until the due date; the subscription then ends on that date.</p>
<p><strong>How to terminate.</strong> The Customer uses the "Cancel your contract" button on the "Subscription" page of the workspace, free of charge. The Seller confirms receipt of the termination on a durable medium and states the date on which the contract ends and its effects. No compensation is due and, unless the law provides otherwise, the current period remains due.</p>
<p><strong>Deletion and transfer.</strong> Deleting the workspace or the account of its owner ends the subscription immediately, with no refund of the current period, subject to Articles 7 and 8. Transferring ownership of the workspace ends the subscription at the end of the current period; the new owner can then subscribe for themselves.</p>

<h2>Article 7 — Right of withdrawal</h2>
<p>A consumer has fourteen days from the conclusion of the contract to withdraw from it, without giving any reason and without costs other than those provided for below. The Seller extends this right, by contract, to every Customer, including professionals.</p>
<p><strong>How to withdraw.</strong> During this period, the Customer uses the "Withdraw from the contract here" button on the "Subscription" page of the workspace, fills in their declaration, then validates it with "Confirm the withdrawal". They may also send the Seller the form below, or any other unambiguous statement, at the address given in Article 1, before the period expires. An acknowledgement of receipt is sent to them on a durable medium.</p>
<p><strong>Immediate start of the Service.</strong> When ordering, the Customer expressly requests that the Service start before the end of the withdrawal period and acknowledges that they will lose their right of withdrawal once the contract has been fully performed.</p>
<p><strong>Effects.</strong> Withdrawal ends the subscription and the workspace returns to the Free plan. The Seller refunds the sums paid, by the same means of payment, without undue delay and at the latest fourteen days after being informed of the decision. It deducts an amount proportionate to the service provided until the decision was communicated, calculated pro rata to the number of days of the paid period.</p>
<h3>Model withdrawal form</h3>
<p>(Please complete and return this form only if you wish to withdraw from the contract.)</p>
<p>For the attention of {{ $seller['name'] }}, {{ $seller['address'] }}, {{ $seller['email'] }}:</p>
<p>I/We (*) hereby notify you of my/our (*) withdrawal from the contract for the provision of the service below:</p>
<p>
    Ordered on:<br>
    Name of the consumer(s):<br>
    Address of the consumer(s):<br>
    Signature of the consumer(s) (only if this form is notified on paper):<br>
    Date:
</p>
<p>(*) Delete as appropriate.</p>

<h2>Article 8 — Legal guarantee of conformity</h2>
<p>The Seller is liable to the consumer for any lack of conformity of the Service that appears throughout the period of its supply, in accordance with Articles L. 224-25-12 et seq. of the Consumer Code. The consumer is entitled to have the Service brought into conformity free of charge or, failing that and under the conditions laid down by those articles, to a price reduction or to termination of the contract. The consumer reports the defect to the address given in Article 1.</p>

<h2>Article 9 — End of the subscription and data</h2>
<p>At the end of the subscription, the workspace returns to the Free plan. No data is deleted as a result, but creations beyond the limits of that plan are refused as long as the workspace exceeds them. The Customer remains able to export their data from the Service.</p>

<h2>Article 10 — Availability</h2>
<p>The Seller uses reasonable means to ensure access to the Service and may interrupt it for maintenance, limiting the inconvenience. The Customer is advised to keep their own exports.</p>

<h2>Article 11 — Liability</h2>
<p>Nothing in these Terms limits the rights that the consumer holds from the law.</p>
<p>Towards a professional Customer, and except in the event of gross negligence or wilful misconduct, the Seller's liability is limited to direct damage and, all events taken together, to the amount paid by that Customer for the twelve months preceding the event giving rise to liability.</p>
<p>Neither party is liable for a failure due to force majeure within the meaning of Article 1218 of the Civil Code.</p>

<h2>Article 12 — Personal data</h2>
<p>The Seller processes the Customer's data to perform the contract and meet its legal obligations, in particular accounting obligations. Stripe processes payment and billing data. Billing data is kept at Stripe and by the Seller after the account is deleted, for the period required by accounting rules. When the photo entry is enabled by the owner of the workspace, the photos sent are transmitted to Anthropic for analysis.</p>
<p>The Customer has the rights of access, rectification, erasure, objection, restriction and portability, which they exercise at the address given in Article 1. They may lodge a complaint with the CNIL.</p>

<h2>Article 13 — Intellectual property</h2>
<p>The source code of the Service is published under the AGPL-3.0 licence. The Customer remains the owner of the data they enter and grants the Seller only the rights necessary to provide the Service.</p>

<h2>Article 14 — Changes to the Terms</h2>
<p>The applicable Terms are those accepted when ordering. The Seller may change them for the future; it informs the Customer by email at least thirty days before they apply. A Customer who refuses them terminates their contract before that date.</p>

<h2>Article 15 — Complaints and mediation</h2>
<p>The Customer first addresses their complaint to the Seller, at the address given in Article 1.</p>
<p>The consumer may then use, free of charge, the consumer mediator to which the Seller belongs: {{ $seller['mediator_name'] }}, {{ $seller['mediator_address'] }}, {{ $seller['mediator_url'] }}.</p>

<h2>Article 16 — Governing law and jurisdiction</h2>
<p>The contract is governed by French law, without depriving the consumer of the mandatory provisions of the law of their habitual residence. The consumer may bring the matter before any court having jurisdiction under the law. Any dispute with a professional Customer falls within the jurisdiction of the courts of the Seller's registered office.</p>

<h2>Annex — Articles of the Consumer Code reproduced pursuant to Article L. 215-4 (statutory text, in French)</h2>
<div lang="fr">
<p><strong>Article L. 215-1.</strong> Pour les contrats de prestations de services conclus pour une durée déterminée avec une clause de reconduction tacite, le professionnel prestataire de services informe le consommateur par écrit, par lettre nominative ou courrier électronique dédiés, au plus tôt trois mois et au plus tard un mois avant le terme de la période autorisant le rejet de la reconduction, de la possibilité de ne pas reconduire le contrat qu'il a conclu avec une clause de reconduction tacite. Cette information, délivrée dans des termes clairs et compréhensibles, mentionne, dans un encadré apparent, la date limite de non-reconduction.</p>
<p>Lorsque cette information ne lui a pas été adressée conformément aux dispositions du premier alinéa, le consommateur peut mettre gratuitement un terme au contrat, à tout moment à compter de la date de reconduction.</p>
<p>Les avances effectuées après la dernière date de reconduction ou, s'agissant des contrats à durée indéterminée, après la date de transformation du contrat initial à durée déterminée, sont dans ce cas remboursées dans un délai de trente jours à compter de la date de résiliation, déduction faite des sommes correspondant, jusqu'à celle-ci, à l'exécution du contrat.</p>
<p>Les dispositions du présent article s'appliquent sans préjudice de celles qui soumettent légalement certains contrats à des règles particulières en ce qui concerne l'information du consommateur.</p>
<p>Par exception au premier alinéa du présent article, pour les contrats de fourniture de service de télévision au sens de l'article 2 de la loi n° 86-1067 du 30 septembre 1986 relative à la liberté de communication et pour les contrats de fourniture de services de médias audiovisuels à la demande, le consommateur peut mettre gratuitement un terme au contrat, à tout moment à compter de la première reconduction, dès lors qu'il change de domicile ou que son foyer fiscal évolue.</p>
<p><strong>Article L. 215-1-1.</strong> Lorsqu'un contrat a été conclu par voie électronique ou a été conclu par un autre moyen et que le professionnel, au jour de la résiliation par le consommateur, offre au consommateur la possibilité de conclure des contrats par voie électronique, la résiliation est rendue possible selon cette modalité.</p>
<p>A cet effet, le professionnel met à la disposition du consommateur une fonctionnalité gratuite permettant d'accomplir, par voie électronique, la notification et les démarches nécessaires à la résiliation du contrat. Lorsque le consommateur notifie la résiliation du contrat, le professionnel lui confirme la réception de la notification et l'informe, sur un support durable et dans des délais raisonnables, de la date à laquelle le contrat prend fin et des effets de la résiliation.</p>
<p>Un décret fixe notamment les modalités techniques de nature à garantir une identification du consommateur et un accès facile, direct et permanent à la fonctionnalité mentionnée au deuxième alinéa, telles que ses modalités de présentation et d'utilisation. Il détermine les informations devant être fournies par le consommateur.</p>
<p><strong>Article L. 215-2.</strong> Les dispositions du présent chapitre, à l'exception de l'article L. 215-1-1, ne sont pas applicables aux exploitants des services d'eau potable et d'assainissement.</p>
<p><strong>Article L. 215-3.</strong> Les dispositions du présent chapitre sont également applicables aux contrats conclus entre des professionnels et des non-professionnels.</p>
<p><strong>Article L. 241-3.</strong> Lorsque le professionnel n'a pas procédé au remboursement dans les conditions prévues à l'article L. 215-1, les sommes dues sont productives d'intérêts au taux légal.</p>
</div>
