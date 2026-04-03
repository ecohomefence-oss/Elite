import './App.css'

const featuredProperties = [
  {
    title: 'Frontline golf villa in Nueva Andalucía',
    price: '€4.950.000',
    meta: '6 beds · 7 baths · 742 m² built',
    tag: 'Marbella',
  },
  {
    title: 'Contemporary sea-view residence in La Zagaleta',
    price: '€11.900.000',
    meta: '8 beds · 9 baths · 1.380 m² built',
    tag: 'Benahavís',
  },
  {
    title: 'Designer penthouse on Marbella Golden Mile',
    price: '€3.250.000',
    meta: '4 beds · 4 baths · 312 m² interior + terraces',
    tag: 'Golden Mile',
  },
]

const lifestyleCards = [
  {
    title: 'Luxury property advisory',
    text: 'Curated homes, off-market access and investment-focused guidance across Marbella, Benahavís, Estepona and Sotogrande.',
  },
  {
    title: 'Local knowledge that closes deals',
    text: 'Golf, beachfront, gated communities, new developments and lifestyle assets matched to how international buyers actually search.',
  },
  {
    title: 'Seller positioning with premium presentation',
    text: 'High-ticket listings framed with sharper design, stronger storytelling and qualification-first lead capture.',
  },
]

const stats = [
  { value: '25+', label: 'Years in property & investment' },
  { value: 'Costa del Sol', label: 'Core market expertise' },
  { value: 'Luxury', label: 'Homes from €500k to ultra-prime' },
]

const zones = ['Marbella', 'Benahavís', 'Estepona', 'Sotogrande', 'Mijas', 'Golden Mile']

function App() {
  return (
    <div className="page-shell">
      <header className="topbar">
        <div className="brand-block">
          <span className="brand-kicker">Elite Properties Spain</span>
          <span className="brand-subtitle">Costa del Sol luxury real estate</span>
        </div>
        <nav className="topnav" aria-label="Main navigation">
          <a href="#collection">Collection</a>
          <a href="#advisory">Advisory</a>
          <a href="#areas">Areas</a>
          <a href="#contact">Contact</a>
        </nav>
      </header>

      <main>
        <section className="hero-section">
          <div className="hero-copy">
            <p className="eyebrow">Luxury real estate on the Costa del Sol</p>
            <h1>Redesign concept for a sharper, higher-value Elite presence.</h1>
            <p className="hero-text">
              A premium homepage direction focused on international buyers, qualified leads,
              luxury credibility and a cleaner visual language ready to evolve into the full site.
            </p>

            <div className="hero-actions">
              <a className="primary-btn" href="#collection">View signature properties</a>
              <a className="secondary-btn" href="#contact">Book a private consultation</a>
            </div>

            <div className="stats-grid">
              {stats.map((stat) => (
                <div key={stat.label} className="stat-card">
                  <strong>{stat.value}</strong>
                  <span>{stat.label}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="hero-visual" aria-hidden="true">
            <div className="hero-panel hero-panel-main">
              <span className="panel-label">Featured market</span>
              <h2>Marbella · Benahavís · Estepona</h2>
              <p>
                Premium waterfront, golf-front and gated-community homes for buyers seeking
                lifestyle, yield and long-term positioning.
              </p>
            </div>
            <div className="hero-panel hero-panel-float">
              <span className="mini-label">Private advisory</span>
              <strong>Buyer sourcing, seller strategy and investment guidance</strong>
            </div>
          </div>
        </section>

        <section className="featured-section" id="collection">
          <div className="section-heading">
            <p className="eyebrow">Signature collection</p>
            <h2>Designed to sell lifestyle, not just square metres.</h2>
          </div>

          <div className="property-grid">
            {featuredProperties.map((property) => (
              <article key={property.title} className="property-card">
                <span className="property-tag">{property.tag}</span>
                <h3>{property.title}</h3>
                <p className="property-meta">{property.meta}</p>
                <div className="property-footer">
                  <strong>{property.price}</strong>
                  <span>Request details →</span>
                </div>
              </article>
            ))}
          </div>
        </section>

        <section className="advisory-section" id="advisory">
          <div className="section-heading narrow">
            <p className="eyebrow">Positioning</p>
            <h2>A cleaner luxury direction built for trust, discovery and lead capture.</h2>
          </div>

          <div className="lifestyle-grid">
            {lifestyleCards.map((card) => (
              <article key={card.title} className="lifestyle-card">
                <h3>{card.title}</h3>
                <p>{card.text}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="areas-section" id="areas">
          <div className="areas-copy">
            <p className="eyebrow">Key areas</p>
            <h2>Built around the places buyers already ask for first.</h2>
            <p>
              The new structure can scale into area landing pages, curated collection pages,
              blog-driven SEO and filtered lead funnels for different buyer intents.
            </p>
          </div>
          <div className="areas-list">
            {zones.map((zone) => (
              <span key={zone}>{zone}</span>
            ))}
          </div>
        </section>

        <section className="contact-section" id="contact">
          <div>
            <p className="eyebrow">Next phase</p>
            <h2>Ready to turn this into the full Elite redesign.</h2>
            <p>
              Next build steps: inner pages, property archive style, area templates, seller page,
              blog structure and lead-generation flows.
            </p>
          </div>
          <a className="primary-btn" href="mailto:jose@elitepropertiesspain.com">Continue with full site build</a>
        </section>
      </main>
    </div>
  )
}

export default App
