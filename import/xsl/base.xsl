<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:php="http://php.net/xsl"
    xmlns:base_dc="http://oai.base-search.net/base_dc/"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xlink="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:doaj="http://www.doaj.org/schemas/">
    <xsl:output method="xml" indent="yes" encoding="utf-8"/>
    <xsl:param name="institution">findex.gbv.de</xsl:param>
    <xsl:param name="collection">BASE</xsl:param>
    <xsl:template match="base_dc:dc">
        <add>
            <doc>
                <!-- ID -->
                <!-- Important: This relies on an <identifier> tag being injected by the OAI-PMH harvester. -->
                <field name="id">
                    <xsl:value-of select="//oai_identifier"/>
                </field>

                <!-- RECORDTYPE -->
                <field name="recordtype">base_dc</field>

                <!-- FULLRECORD -->
                <!-- disabled for now; records are so large that they cause memory problems! -->
                <field name="fullrecord">
                    <xsl:copy-of select="php:function('VuFind::xmlAsText', /base_dc:dc)"/>
                </field>

                <!-- ALLFIELDS -->
                <field name="allfields">
                    <xsl:value-of select="normalize-space(string(/base_dc:dc))"/>
                </field>

                <!-- INSTITUTION -->
                <field name="institution">
                    <xsl:value-of select="$institution" />
                </field>

                <!-- COLLECTION -->
                <xsl:if test="//base_dc:collname">
                    <xsl:for-each select="//base_dc:collname">
                        <xsl:if test="normalize-space()">
                            <field name="collection">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>

               <!-- Open Access? -->
                <xsl:if test="//base_dc:oa">
                    <xsl:for-each select="//base_dc:oa">
                        <xsl:if test="normalize-space()">
                            <field name="is_oa_txtF_mv">
                               <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>


                <!-- LANGUAGE -->
                <!-- TODO: add language support; in practice, there don't seem to be
                     many records with <language> tags in them.  If we encounter any,
                     the code below is partially complete, but we probably need to
                     build a new language map for ISO 639-2b, which is the standard
                     specified by the DOAJ XML schema.
                <xsl:if test="/doaj:record/doaj:language">
                    <xsl:for-each select="/doaj:record/doaj:language">
                        <xsl:if test="string-length() > 0">
                            <field name="language">
                                <xsl:value-of select="php:function('VuFind::mapString', normalize-space(string(.)), 'language_map_iso639-1.properties')"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>
                -->
                <xsl:if test="//base_dc:lang">
                    <xsl:for-each select="//base_dc:lang">
                        <xsl:if test="normalize-space()">
                            <field name="language">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>

                <!-- FORMAT -->
                <xsl:if test="//base_dc:typenorm">
                    <xsl:for-each select="//base_dc:typenorm">
                        <xsl:if test="normalize-space()">
                            <field name="format">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>

                <!-- AUTHOR -->
                <xsl:if test="//dc:creator">
                    <xsl:for-each select="//dc:creator">
                        <xsl:if test="normalize-space()">
                            <field name="author">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>
                <xsl:if test="//base_dc:contributor">
                    <xsl:for-each select="//base_dc:contributor">
                        <xsl:if test="normalize-space()">
                            <field name="author2">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>

                <!-- TITLE -->
                <xsl:if test="//dc:title[normalize-space()]">
                    <field name="title">
                        <xsl:value-of select="//dc:title[normalize-space()]"/>
                    </field>
                    <field name="title_short">
                        <xsl:value-of select="//dc:title[normalize-space()]"/>
                    </field>
                    <field name="title_full">
                        <xsl:value-of select="//dc:title[normalize-space()]"/>
                    </field>
                    <field name="title_sort">
                        <xsl:value-of select="php:function('VuFind::stripArticles', string(//dc:title[normalize-space()]))"/>
                    </field>
                </xsl:if>

                <!-- PUBLISHER -->
                <xsl:if test="//dc:publisher">
                    <xsl:for-each select="//dc:publisher">
                        <xsl:if test="normalize-space()">
                            <field name="publisher">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>

                 <!-- SERIES -->
<!--                <xsl:if test="//doaj:journalTitle[normalize-space()]">
                    <field name="series">
                        <xsl:value-of select="//doaj:journalTitle[normalize-space()]"/>
                    </field>
                </xsl:if> -->

                <!-- ISSN  -->
<!--                <xsl:if test="//doaj:issn[normalize-space()]">
                    <field name="issn">
                        <xsl:value-of select="//doaj:issn[normalize-space()]"/>
                    </field>
                </xsl:if> -->

                <!-- ISSN  -->
<!--                <xsl:if test="//doaj:eissn[normalize-space()]">
                    <field name="issn">
                        <xsl:value-of select="//doaj:eissn[normalize-space()]"/>
                    </field>
                </xsl:if> -->

                <!-- PUBLISHDATE -->
                <xsl:if test="//base_dc:year">
                    <field name="publishDate">
                        <xsl:value-of select="//base_dc:year"/>
                    </field>
                    <field name="publishDateSort">
                        <xsl:value-of select="//base_dc:year"/>
                    </field>
                </xsl:if>

                <!-- DESCRIPTION -->
                <xsl:if test="//dc:description">
                    <field name="description">
                        <xsl:value-of select="//dc:description" />
                    </field>
                </xsl:if>

                <!-- SUBJECT -->
                <xsl:if test="//dc:subject">
                    <xsl:for-each select="//dc:subject">
                        <xsl:if test="string-length() > 0">
                            <field name="topic">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if>

                <!-- URL -->
                <xsl:if test="//base_dc:link">
                    <xsl:for-each select="//base_dc:link">
                        <xsl:if test="normalize-space()">
                            <field name="url">
                                <xsl:value-of select="normalize-space()"/>
                            </field>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:if> 
            </doc>
        </add>
    </xsl:template>
</xsl:stylesheet>
