{{--
    The terms of sale, in French (the version that prevails). Plain HTML only: the same partial is rendered on the page and in the PDF sent with
    the confirmation of a subscription. It receives $seller (see App\Legal\Seller) and $plans (App\Landing\PricingPlans).
--}}
<h2>Préambule</h2>
<p>Les présentes conditions générales de vente (les « CGV ») régissent la souscription de l'abonnement payant « Pro » au service en ligne d'inventaire inventaire.cloud (le « Service »). Elles sont conclues entre le vendeur désigné à l'article 1 (le « Vendeur ») et toute personne qui souscrit cet abonnement (le « Client »), qu'elle agisse comme consommateur, non-professionnel ou professionnel.</p>
<p>Le Client les accepte en cochant la case prévue lors de la commande. Elles sont rédigées en français, langue du contrat. Une traduction peut être proposée pour information ; seule la version française fait foi.</p>

<h2>Article 1 — Vendeur</h2>
<p>
    {{ $seller['name'] }}, {{ $seller['form'] }}@if ($seller['capital']), au capital de {{ $seller['capital'] }}@endif<br>
    Siège : {{ $seller['address'] }}<br>
    Immatriculation : {{ $seller['registration'] }}<br>
    N° de TVA intracommunautaire : {{ $seller['vat_number'] }}<br>
    Courriel : {{ $seller['email'] }} — Téléphone : {{ $seller['phone'] }}
</p>

<h2>Article 2 — Service et formules</h2>
<p>Le Service permet de créer et de gérer des inventaires en ligne. L'abonnement se rattache à un espace de travail : seul le propriétaire de l'espace le souscrit et le gère, et tous les membres de l'espace bénéficient de ses limites.</p>
<table>
    <thead>
        <tr><th scope="col">Limite par espace de travail</th><th scope="col">Gratuit</th><th scope="col">Pro</th></tr>
    </thead>
    <tbody>
        <tr><td>Inventaires</td><td>{{ $plans->inventories('free') }}</td><td>{{ $plans->inventories('pro') }}</td></tr>
        <tr><td>Articles par inventaire</td><td>{{ $plans->items('free') }}</td><td>{{ $plans->items('pro') }}</td></tr>
        <tr><td>Stockage des fichiers</td><td>{{ \App\Support\FileSize::format($plans->limit('free', 'storage_bytes')) }}</td><td>{{ \App\Support\FileSize::format($plans->limit('pro', 'storage_bytes')) }}</td></tr>
        <tr><td>Membres, invitations en attente comprises</td><td>{{ $plans->members('free') }}</td><td>{{ $plans->members('pro') }}</td></tr>
        <tr><td>Liens de partage par inventaire</td><td>{{ $plans->shareLinks('free') }}</td><td>{{ $plans->shareLinks('pro') }}</td></tr>
    </tbody>
</table>
<p>La formule Gratuit n'est soumise à aucun paiement et n'entre pas dans le champ de la vente régie par les présentes CGV.</p>

<h2>Article 3 — Prix et TVA</h2>
<p>L'abonnement Pro coûte {{ $plans->monthlyPrice() }} TTC par mois{{ $plans->yearlyPrice() === null ? '' : ', ou '.$plans->yearlyPrice().' TTC par an' }}, payables d'avance. Ces prix incluent la TVA au taux applicable en France ; la facture en détaille le montant.</p>
<p>La TVA est déterminée selon la réglementation applicable à la date de la facture et au lieu d'établissement du Client. Un professionnel établi dans un autre État membre de l'Union européenne qui communique un numéro de TVA valide peut être facturé hors taxe au titre de l'autoliquidation.</p>
<p>Le Vendeur peut modifier ses prix pour l'avenir. Il en informe le Client par courriel au moins trente jours avant l'échéance à laquelle le nouveau prix s'applique. Le Client qui le refuse résilie son contrat avant cette échéance.</p>

<h2>Article 4 — Commande</h2>
<p>Le propriétaire de l'espace de travail choisit sa périodicité depuis la page « Abonnement » de l'espace. Il est alors dirigé vers la page de paiement de Stripe, qui récapitule le service, le prix et la périodicité et indique les moyens de paiement acceptés. Il accepte les présentes CGV, demande le début immédiat du Service (article 7) et valide sa commande, laquelle l'oblige au paiement.</p>
<p>Le contrat est conclu à la validation du paiement. Le Vendeur adresse au Client, sur support durable, une confirmation accompagnée des présentes CGV et du formulaire type de rétractation.</p>

<h2>Article 5 — Paiement</h2>
<p>Le paiement est traité par Stripe ; le Vendeur n'a accès à aucun numéro complet de carte. Le moyen de paiement enregistré est débité à la souscription, puis au début de chaque période. Les factures sont disponibles depuis la gestion de l'abonnement.</p>
<p>En cas d'échec d'un paiement, de nouvelles tentatives sont effectuées et le Client en est informé. Si le paiement reste impayé à l'issue de ces tentatives, l'abonnement prend fin et l'espace de travail revient à la formule Gratuit (article 9).</p>
<p>Pour un Client professionnel, tout retard de paiement entraîne de plein droit des pénalités égales à trois fois le taux d'intérêt légal et une indemnité forfaitaire de recouvrement de 40 €.</p>

<h2>Article 6 — Durée, reconduction et résiliation</h2>
<p><strong>Abonnement mensuel.</strong> Il est conclu pour une durée indéterminée, sans durée minimale, et payé mois par mois. Le Client peut le résilier à tout moment ; il prend fin à l'issue du mois déjà payé.</p>
<p><strong>Abonnement annuel.</strong> Il est conclu pour une durée d'un an, reconduite tacitement par périodes d'un an. Le Vendeur informe le Client de la possibilité de ne pas le reconduire, par courriel dédié, au plus tôt trois mois et au plus tard un mois avant l'échéance. Le Client peut demander la non-reconduction jusqu'à l'échéance ; l'abonnement prend alors fin à cette date.</p>
<p><strong>Comment résilier.</strong> Le Client utilise le bouton « Résilier votre contrat » de la page « Abonnement » de l'espace de travail, gratuitement. Le Vendeur confirme la réception de la résiliation sur support durable et indique la date de fin du contrat et ses effets. Aucune indemnité n'est due et, sauf disposition légale contraire, la période en cours reste due.</p>
<p><strong>Suppression et transfert.</strong> La suppression de l'espace de travail ou du compte de son propriétaire met fin immédiatement à l'abonnement, sans remboursement de la période en cours, sous réserve des articles 7 et 8. Le transfert de propriété de l'espace met fin à l'abonnement au terme de la période en cours ; le nouveau propriétaire peut alors souscrire pour lui-même.</p>

<h2>Article 7 — Droit de rétractation</h2>
<p>Le consommateur dispose de quatorze jours à compter de la conclusion du contrat pour se rétracter, sans motif et sans frais autres que ceux prévus ci-dessous. Le jour de la conclusion n'est pas compté ; le délai prend fin à la dernière heure du quatorzième jour et, s'il expire un samedi, un dimanche ou un jour férié ou chômé, il est prorogé jusqu'au premier jour ouvrable suivant. Le Vendeur étend ce droit, par contrat, à tout Client, y compris professionnel.</p>
<p><strong>Comment se rétracter.</strong> Pendant ce délai, le Client utilise le bouton « Renoncer au contrat ici » de la page « Abonnement » de l'espace de travail, renseigne sa déclaration, puis la valide avec « Confirmer la rétractation ». Il peut aussi adresser au Vendeur le formulaire ci-dessous, ou toute autre déclaration dénuée d'ambiguïté, à l'adresse indiquée à l'article 1, avant l'expiration du délai. Un accusé de réception lui est adressé sur support durable.</p>
<p><strong>Début immédiat du Service.</strong> Lors de la commande, le Client demande expressément que le Service commence avant la fin du délai de rétractation et reconnaît qu'il perdra son droit de rétractation une fois le contrat entièrement exécuté.</p>
<p><strong>Effets.</strong> La rétractation met fin à l'abonnement et l'espace de travail revient à la formule Gratuit. Le Vendeur rembourse les sommes versées, par le même moyen de paiement, sans retard injustifié et au plus tard quatorze jours après avoir été informé de la décision. Il en déduit un montant proportionné au service fourni jusqu'à la communication de la décision, calculé au prorata du nombre de jours de la période payée.</p>
<h3>Formulaire type de rétractation</h3>
<p>(Veuillez compléter et renvoyer le présent formulaire uniquement si vous souhaitez vous rétracter du contrat.)</p>
<p>À l'attention de {{ $seller['name'] }}, {{ $seller['address'] }}, {{ $seller['email'] }} :</p>
<p>Je/nous (*) vous notifie/notifions (*) par la présente ma/notre (*) rétractation du contrat portant sur la prestation de services ci-dessous :</p>
<p>
    Commandé le :<br>
    Nom du (des) consommateur(s) :<br>
    Adresse du (des) consommateur(s) :<br>
    Signature du (des) consommateur(s) (uniquement en cas de notification du présent formulaire sur papier) :<br>
    Date :
</p>
<p>(*) Rayez la mention inutile.</p>

<h2>Article 8 — Garantie légale de conformité</h2>
<p>Le Vendeur répond, envers le consommateur, des défauts de conformité du Service apparaissant pendant toute la durée de sa fourniture, conformément aux articles L. 224-25-12 et suivants du code de la consommation. Le consommateur a droit à la mise en conformité sans frais ou, à défaut et dans les conditions prévues par ces articles, à une réduction du prix ou à la résolution du contrat. Il signale le défaut à l'adresse indiquée à l'article 1.</p>

<h2>Article 9 — Fin de l'abonnement et données</h2>
<p>À la fin de l'abonnement, l'espace de travail revient à la formule Gratuit. Aucune donnée n'est supprimée de ce fait, mais les créations au-delà des limites de cette formule sont refusées tant que l'espace les dépasse. Le Client conserve la possibilité d'exporter ses données depuis le Service.</p>

<h2>Article 10 — Disponibilité</h2>
<p>Le Vendeur met en œuvre les moyens raisonnables pour assurer l'accès au Service et peut l'interrompre pour maintenance, en limitant la gêne. Il est conseillé au Client de conserver ses propres exports.</p>

<h2>Article 11 — Responsabilité</h2>
<p>Aucune stipulation des présentes ne limite les droits que le consommateur tient de la loi.</p>
<p>Envers un Client professionnel, et sauf faute lourde ou dolosive, la responsabilité du Vendeur est limitée aux dommages directs et, tous faits confondus, au montant payé par ce Client au titre des douze mois précédant le fait générateur.</p>
<p>Aucune partie n'est responsable d'un manquement dû à un cas de force majeure au sens de l'article 1218 du code civil.</p>

<h2>Article 12 — Données personnelles</h2>
<p>Le Vendeur traite les données du Client pour exécuter le contrat et respecter ses obligations légales, notamment comptables. Stripe traite les données de paiement et de facturation. Les données de facturation sont conservées chez Stripe et par le Vendeur après la suppression du compte, pour la durée requise par la comptabilité. Lorsque la saisie par photos est activée par le propriétaire de l'espace, les photos envoyées sont transmises à Anthropic pour analyse.</p>
<p>Le Client dispose des droits d'accès, de rectification, d'effacement, d'opposition, de limitation et de portabilité, qu'il exerce à l'adresse indiquée à l'article 1. Il peut saisir la CNIL.</p>

<h2>Article 13 — Propriété intellectuelle</h2>
<p>Le code source du Service est publié sous licence AGPL-3.0. Le Client reste propriétaire des données qu'il saisit et n'accorde au Vendeur que les droits nécessaires à la fourniture du Service.</p>

<h2>Article 14 — Modification des CGV</h2>
<p>Les CGV applicables sont celles acceptées à la commande. Le Vendeur peut les modifier pour l'avenir ; il en informe le Client par courriel au moins trente jours avant leur application. Le Client qui les refuse résilie son contrat avant cette date.</p>

<h2>Article 15 — Réclamations et médiation</h2>
<p>Le Client adresse d'abord sa réclamation au Vendeur, à l'adresse indiquée à l'article 1.</p>
<p>Le consommateur peut ensuite recourir gratuitement au médiateur de la consommation dont relève le Vendeur : {{ $seller['mediator_name'] }}, {{ $seller['mediator_address'] }}, {{ $seller['mediator_url'] }}.</p>

<h2>Article 16 — Droit applicable et juridiction</h2>
<p>Le contrat est soumis au droit français, sans priver le consommateur des dispositions impératives de la loi de sa résidence habituelle. Le consommateur peut saisir toute juridiction compétente en vertu de la loi. Tout litige avec un Client professionnel relève des tribunaux du ressort du siège du Vendeur.</p>

<h2>Annexe — Articles du code de la consommation reproduits en application de l'article L. 215-4</h2>
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
